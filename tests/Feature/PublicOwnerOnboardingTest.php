<?php

namespace Tests\Feature;

use App\Actions\GenerateAccountSchedule;
use App\Actions\PublishOwnerOnboarding;
use App\Enums\AccountRole;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\PublicScheduleView;
use App\Models\Account;
use App\Models\AccountOnboarding;
use App\Models\AccountSubscriptionPayment;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserPhoneOtpChallenge;
use App\Support\DemoStudioFixture;
use App\Support\Onboarding\OwnerPhoneOtpService;
use App\Support\Pwa\StudioPwaIconGenerator;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class PublicOwnerOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-21 09:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_public_registration_is_closed_by_default_while_an_authenticated_owner_can_resume(): void
    {
        config()->set('ladna.public_owner_onboarding_enabled', false);

        $this->get(route('register'))->assertNotFound();
        $this->get('/register')->assertNotFound();

        $this->actingAs($this->newOwner())
            ->get(route('onboarding.show', ['step' => 1]))
            ->assertOk();
    }

    public function test_disabling_registration_does_not_interrupt_an_existing_onboarding(): void
    {
        $this->preparePublicOnboarding();
        [$user] = $this->ownerAfterStepOne();
        config()->set('ladna.public_owner_onboarding_enabled', false);

        $this->actingAs($user)
            ->get(route('onboarding.show', ['step' => 2]))
            ->assertOk()
            ->assertSee(__('app.onboarding.steps.2.title'));

        $this->post(route('onboarding.store', ['step' => 2]), [
            'location_name' => 'Лівий берег',
            'address' => 'Київ, вул. Русанівська, 10',
            'room_name' => 'Основна зала',
            'capacity' => 10,
        ])->assertRedirect(route('onboarding.show', ['step' => 3]));
    }

    public function test_registration_has_an_ip_limit_even_when_email_addresses_change(): void
    {
        config()->set('ladna.public_owner_onboarding_enabled', true);
        $server = ['REMOTE_ADDR' => '203.0.113.101'];

        foreach (range(1, 10) as $attempt) {
            $this->withServerVariables($server)
                ->post(route('register'), ['email' => "owner-{$attempt}@example.com"])
                ->assertRedirect();
        }

        $this->withServerVariables($server)
            ->post(route('register'), ['email' => 'owner-11@example.com'])
            ->assertStatus(429);
    }

    public function test_owner_otp_has_a_user_limit_even_when_phone_numbers_change(): void
    {
        $this->preparePublicOnboarding();
        [$user] = $this->ownerAtReviewStep();
        $server = ['REMOTE_ADDR' => '203.0.113.102'];

        foreach (range(1, 3) as $attempt) {
            $this->actingAs($user)
                ->withServerVariables($server)
                ->post(route('onboarding.otp.send'), [
                    'phone' => '+3805011100'.str_pad((string) $attempt, 2, '0', STR_PAD_LEFT),
                ])
                ->assertRedirect();
        }

        $this->withServerVariables($server)
            ->post(route('onboarding.otp.send'), ['phone' => '+380501110099'])
            ->assertStatus(429);
    }

    public function test_registration_requires_legal_consent_and_a_valid_turnstile_challenge(): void
    {
        $this->preparePublicOnboarding();
        Http::fake([
            'https://challenges.cloudflare.com/*' => Http::response(['success' => false], 200),
        ]);

        $payload = [
            'name' => 'Олена Власниця',
            'email' => 'validation-owner@example.com',
            'phone' => '050 111 22 33',
            'password' => 'password',
            'password_confirmation' => 'password',
            'cf-turnstile-response' => 'invalid-turnstile-token',
        ];

        $this->post(route('register'), $payload)
            ->assertSessionHasErrors('legal_accepted');

        $this->post(route('register'), [...$payload, 'legal_accepted' => '1'])
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->assertFalse(User::query()->where('email', $payload['email'])->exists());
    }

    public function test_turnstile_bypass_is_ignored_outside_the_local_environment(): void
    {
        $this->preparePublicOnboarding();
        config()->set('ladna.public_owner_onboarding_turnstile_bypass', true);
        IntegrationSetting::query()
            ->where('provider', IntegrationProvider::CloudflareTurnstile->value)
            ->delete();
        $originalEnvironment = app()->environment();

        try {
            app()->detectEnvironment(fn (): string => 'production');
            $this->get(route('register'))->assertNotFound();
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_local_turnstile_bypass_allows_registration_without_a_widget_or_token(): void
    {
        $this->preparePublicOnboarding();
        config()->set('ladna.public_owner_onboarding_turnstile_bypass', true);
        IntegrationSetting::query()
            ->where('provider', IntegrationProvider::CloudflareTurnstile->value)
            ->delete();
        $originalEnvironment = app()->environment();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Http::preventStrayRequests();

        try {
            app()->detectEnvironment(fn (): string => 'local');

            $this->get(route('register'))
                ->assertOk()
                ->assertDontSee('challenges.cloudflare.com', false);

            $this->post(route('register'), [
                'name' => 'Локальна Власниця',
                'email' => 'local-owner@example.com',
                'phone' => '050 111 22 33',
                'password' => 'password',
                'password_confirmation' => 'password',
                'legal_accepted' => '1',
            ])->assertRedirect(route('onboarding.show', ['step' => 1]));

            $this->assertAuthenticated();
            $this->assertTrue(User::query()->where('email', 'local-owner@example.com')->exists());
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_local_turnstile_bypass_allows_sending_the_owner_otp_without_a_token(): void
    {
        $this->preparePublicOnboarding();
        config()->set('ladna.public_owner_onboarding_turnstile_bypass', true);
        IntegrationSetting::query()
            ->where('provider', IntegrationProvider::CloudflareTurnstile->value)
            ->delete();
        $originalEnvironment = app()->environment();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'sms-1']]], 200),
        ]);

        try {
            app()->detectEnvironment(fn (): string => 'local');
            [$user] = $this->ownerAtReviewStep();

            $this->actingAs($user)->post(route('onboarding.otp.send'), [
                'phone' => $user->phone,
            ])->assertRedirect(route('onboarding.show', ['step' => 6]));

            $this->assertSame(1, $user->phoneOtpChallenges()->count());
            Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'challenges.cloudflare.com'));
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_local_turnstile_bypass_still_requires_central_sms(): void
    {
        $this->preparePublicOnboarding();
        config()->set('ladna.public_owner_onboarding_turnstile_bypass', true);
        IntegrationSetting::query()
            ->whereIn('category', [
                IntegrationCategory::Authentication->value,
                IntegrationCategory::Messaging->value,
            ])
            ->delete();
        $originalEnvironment = app()->environment();

        try {
            app()->detectEnvironment(fn (): string => 'local');
            $this->get(route('register'))->assertNotFound();
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_registration_creates_only_an_authenticated_owner_user_with_legal_evidence(): void
    {
        $this->preparePublicOnboarding();
        $this->fakeSuccessfulGateways();

        $this->get(route('register'))
            ->assertOk()
            ->assertSee(__('app.onboarding.registration_title'))
            ->assertSee(__('app.onboarding.registration_kicker'));

        $this->post(route('register'), [
            'name' => 'Олена Власниця',
            'email' => 'owner@example.com',
            'phone' => '050 111 22 33',
            'password' => 'password',
            'password_confirmation' => 'password',
            'legal_accepted' => '1',
            'cf-turnstile-response' => 'turnstile-token',
        ])->assertRedirect(route('onboarding.show', ['step' => 1]));

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('+380501112233', $user->phone);
        $this->assertNull($user->phone_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->privacy_accepted_at);
        $this->assertFalse($user->accounts()->exists());
    }

    public function test_landing_keeps_the_protected_demo_link_when_registration_is_available(): void
    {
        $this->preparePublicOnboarding();
        Account::factory()->demoReadonly()->create([
            'slug' => DemoStudioFixture::AccountSlug,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('href="'.route('demo.login', [], false).'"', false)
            ->assertSee('data-pricing-demo-cta', false)
            ->assertSee(__('app.onboarding.registration_cta'))
            ->assertSee(__('app.landing.final_cta'));
    }

    public function test_step_one_creates_the_account_and_trial_without_payment(): void
    {
        $priceVersion = $this->preparePublicOnboarding(trialDays: 31);
        Storage::fake('public');
        $user = $this->newOwner();

        $this->actingAs($user)
            ->post(route('onboarding.store', ['step' => 1]), [
                'studio_stage' => 'operating',
                'studio_name' => 'Luna Pole',
                'location_count' => 2,
            ])
            ->assertRedirect(route('onboarding.show', ['step' => 2]));

        $account = $user->accounts()->firstOrFail();
        $onboarding = $account->onboarding()->firstOrFail();
        $subscription = $account->subscription()->firstOrFail();

        $this->assertSame('Luna Pole', $account->name);
        $this->assertSame(PublicScheduleView::CompactBooking, $account->publicScheduleView());
        $this->assertTrue($account->isOwnedBy($user));
        $this->assertSame(2, $onboarding->current_step);
        $this->assertSame(2, $onboarding->stepAnswers(1)['location_count']);
        $this->assertSame($priceVersion->id, $subscription->subscription_price_version_id);
        $this->assertTrue($subscription->trial_ends_at->equalTo(now()->addDays(31)));
        $this->assertSame(1, $subscription->billable_location_count);
        $this->assertSame(0, AccountSubscriptionPayment::query()->count());
        $this->assertSame(0, Location::query()->whereBelongsTo($account)->count());
    }

    public function test_step_one_rolls_back_when_public_enrollment_is_unavailable(): void
    {
        $this->preparePlatformIntegrations();
        config()->set('ladna.public_owner_onboarding_enabled', true);
        config()->set('ladna.saas_billing_v2_enabled', true);
        $user = $this->newOwner();

        $this->actingAs($user)
            ->post(route('onboarding.store', ['step' => 1]), [
                'studio_stage' => 'operating',
                'studio_name' => 'Unavailable Studio',
                'location_count' => 1,
            ])
            ->assertSessionHasErrors('studio_name');

        $this->assertFalse($user->accounts()->exists());
        $this->assertSame(0, AccountOnboarding::query()->count());
    }

    public function test_progress_resumes_and_future_steps_are_gated_while_back_edits_are_kept(): void
    {
        $this->preparePublicOnboarding();
        [$user, $account] = $this->ownerAfterStepOne();

        $this->actingAs($user)
            ->post(route('onboarding.store', ['step' => 2]), [
                'location_name' => 'Поділ',
                'address' => 'Київ, вул. Верхній Вал, 10',
                'room_name' => 'Зала A',
                'capacity' => 12,
            ])
            ->assertRedirect(route('onboarding.show', ['step' => 3]));

        $this->get(route('onboarding.show', ['step' => 5]))
            ->assertRedirect(route('onboarding.show', ['step' => 3]));

        $this->post(route('onboarding.store', ['step' => 1]), [
            'studio_stage' => 'preparing',
            'studio_name' => 'Luna Pole Kyiv',
            'location_count' => 3,
        ])->assertRedirect(route('onboarding.show', ['step' => 2]));

        $this->assertSame(3, $account->onboarding->refresh()->current_step);
        $this->assertSame('Luna Pole Kyiv', $account->refresh()->name);
        $this->assertSame('Поділ', $account->onboarding->stepAnswers(2)['location_name']);

        $this->post(route('logout'));
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard.index', absolute: false));

        $this->get(route('dashboard.index'))
            ->assertRedirect(route('onboarding.show', ['step' => 3]));
    }

    public function test_every_saved_step_can_be_reopened_without_losing_its_screen(): void
    {
        $this->preparePublicOnboarding();
        [$user] = $this->ownerAtReviewStep();
        $this->actingAs($user);

        foreach (range(1, AccountOnboarding::LastStep) as $step) {
            $this->get(route('onboarding.show', ['step' => $step]))
                ->assertOk()
                ->assertSee(__('app.onboarding.steps.'.$step.'.title'));
        }
    }

    public function test_turnstile_blocks_owner_otp_and_valid_code_verifies_the_current_phone(): void
    {
        $this->preparePublicOnboarding();
        [$user] = $this->ownerAtReviewStep();

        Http::fake([
            'https://challenges.cloudflare.com/*' => fn (HttpRequest $request) => Http::response([
                'success' => $request->data()['response'] === 'good-token',
            ], 200),
            'https://api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'sms-1']]], 200),
        ]);

        $this->actingAs($user)
            ->post(route('onboarding.otp.send'), [
                'phone' => '+380501112233',
                'cf-turnstile-response' => 'bad-token',
            ])
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->assertSame(0, UserPhoneOtpChallenge::query()->whereBelongsTo($user)->count());

        $this->post(route('onboarding.otp.send'), [
            'phone' => '067 222 33 44',
            'cf-turnstile-response' => 'good-token',
        ])->assertRedirect(route('onboarding.show', ['step' => 6]));

        $this->assertSame('+380672223344', $user->refresh()->phone);
        $this->assertNull($user->phone_verified_at);

        $this->post(route('onboarding.otp.verify'), ['otp_code' => '000000'])
            ->assertSessionHasErrors('otp_code');
        $this->assertSame(1, UserPhoneOtpChallenge::query()->whereBelongsTo($user)->latest()->firstOrFail()->attempts);

        $this->post(route('onboarding.otp.verify'), ['otp_code' => '123456'])
            ->assertRedirect(route('onboarding.show', ['step' => 6]));

        $this->assertNotNull($user->refresh()->phone_verified_at);
        $this->assertNotNull($user->phoneOtpChallenges()->latest()->firstOrFail()->consumed_at);
    }

    public function test_owner_otp_uses_the_selected_central_sms_provider(): void
    {
        $this->preparePublicOnboarding();
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::Smsclub->value,
            'category' => IntegrationCategory::Messaging->value,
            'is_enabled' => true,
            'credentials' => [
                'bearer_token' => 'smsclub-token',
                'src_addr' => 'Ladna',
            ],
        ]);
        SystemSetting::setValue(SystemSetting::CentralSmsProviderKey, IntegrationProvider::Smsclub->value);
        [$user] = $this->ownerAtReviewStep();

        Http::fake([
            'https://challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
            'https://im.smsclub.mobi/*' => Http::response(['info' => [['id' => 'smsclub-1']]], 200),
            'https://api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'turbo-1']]], 200),
        ]);

        $this->actingAs($user)
            ->post(route('onboarding.otp.send'), [
                'phone' => $user->phone,
                'cf-turnstile-response' => 'good-token',
            ])
            ->assertRedirect(route('onboarding.show', ['step' => 6]));

        $this->assertSame(
            IntegrationProvider::Smsclub->value,
            $user->phoneOtpChallenges()->latest()->firstOrFail()->provider,
        );
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'im.smsclub.mobi'));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'api.turbosms.ua'));
    }

    public function test_expired_codes_and_resend_limits_are_rejected(): void
    {
        $this->preparePublicOnboarding();
        [$user] = $this->ownerAtReviewStep();
        $this->fakeSuccessfulGateways();
        $this->actingAs($user)->post(route('onboarding.otp.send'), [
            'phone' => $user->phone,
            'cf-turnstile-response' => 'good-token',
        ]);

        $challenge = $user->phoneOtpChallenges()->latest()->firstOrFail();
        $challenge->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->post(route('onboarding.otp.verify'), ['otp_code' => '123456'])
            ->assertSessionHasErrors('otp_code');

        $this->post(route('onboarding.otp.send'), [
            'phone' => $user->phone,
            'cf-turnstile-response' => 'good-token',
        ])->assertRedirect(route('onboarding.show', ['step' => 6]));

        $active = $user->phoneOtpChallenges()->latest()->firstOrFail();
        $active->forceFill([
            'resend_available_at' => now()->subSecond(),
            'send_count' => config('customer_auth.otp.max_sends'),
        ])->save();

        $this->post(route('onboarding.otp.send'), [
            'phone' => $user->phone,
            'cf-turnstile-response' => 'good-token',
        ])->assertSessionHasErrors('phone');
    }

    public function test_otp_attempt_limit_blocks_even_a_later_correct_code(): void
    {
        $this->preparePublicOnboarding();
        [$user] = $this->ownerAtReviewStep();
        $this->fakeSuccessfulGateways();

        $this->actingAs($user)->post(route('onboarding.otp.send'), [
            'phone' => $user->phone,
            'cf-turnstile-response' => 'good-token',
        ])->assertRedirect(route('onboarding.show', ['step' => 6]));

        $otpService = app(OwnerPhoneOtpService::class);

        for ($attempt = 0; $attempt < (int) config('customer_auth.otp.max_attempts'); $attempt++) {
            $this->assertFalse($otpService->verify($user, '000000')->ok);
        }

        $this->assertFalse($otpService->verify($user, '123456')->ok);
        $this->assertNull($user->refresh()->phone_verified_at);
        $this->assertSame(
            (int) config('customer_auth.otp.max_attempts'),
            $user->phoneOtpChallenges()->latest()->firstOrFail()->attempts,
        );
    }

    public function test_publish_is_blocked_until_the_owner_phone_is_verified(): void
    {
        $this->preparePublicOnboarding();
        [$user, $account] = $this->ownerAtReviewStep();

        $this->actingAs($user)
            ->get(route('onboarding.show', ['step' => 6]))
            ->assertOk()
            ->assertSee(__('app.onboarding.verify_phone_title'))
            ->assertSee(__('app.onboarding.publish_requires_verification'));

        $this->post(route('onboarding.publish'))
            ->assertSessionHasErrors('otp_code');

        $this->assertFalse($account->locations()->exists());
        $this->assertNull($account->onboarding()->firstOrFail()->completed_at);
        $this->assertFalse($account->refresh()->allow_guest_public_booking);
    }

    public function test_publish_rolls_back_every_business_entity_when_schedule_generation_fails(): void
    {
        $this->preparePublicOnboarding();
        [$user, $account] = $this->ownerAtReviewStep();
        $user->forceFill(['phone_verified_at' => now()])->save();
        $this->mock(GenerateAccountSchedule::class)
            ->shouldReceive('execute')
            ->once()
            ->andThrow(new LogicException('Generation failed.'));

        try {
            app(PublishOwnerOnboarding::class)->execute($account->onboarding()->firstOrFail(), $user);
            $this->fail('The publication was expected to fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Generation failed.', $exception->getMessage());
        }

        $this->assertFalse($account->locations()->exists());
        $this->assertFalse($account->rooms()->exists());
        $this->assertFalse($account->trainers()->exists());
        $this->assertFalse($account->activityDirections()->exists());
        $this->assertFalse($account->classTypes()->exists());
        $this->assertFalse($account->scheduleSeries()->exists());
        $this->assertFalse($account->scheduledClasses()->exists());
        $this->assertNull($account->onboarding()->firstOrFail()->completed_at);
        $this->assertFalse($account->refresh()->allow_guest_public_booking);
    }

    public function test_publish_is_atomic_idempotent_and_creates_a_public_guest_bookable_class(): void
    {
        Mail::fake();
        $this->mock(StudioPwaIconGenerator::class)
            ->shouldReceive('ensure')
            ->twice();
        $this->preparePublicOnboarding();
        [$user, $account] = $this->ownerAtReviewStep();
        $user->forceFill(['phone_verified_at' => now()])->save();

        $this->actingAs($user)
            ->post(route('onboarding.publish'))
            ->assertRedirect(route('onboarding.success'));

        $this->post(route('onboarding.publish'))
            ->assertRedirect(route('onboarding.success'));

        $this->get(route('onboarding.success'))
            ->assertOk()
            ->assertSee(__('app.onboarding.success_title'))
            ->assertSee(__('app.onboarding.share_title'));

        $account->refresh();
        $onboarding = $account->onboarding()->firstOrFail();
        $primaryLocation = $account->locations()->active()->firstOrFail();
        $placeholder = $account->locations()->where('is_active', false)->firstOrFail();
        $trainer = $account->trainers()->firstOrFail();
        $scheduledClass = $account->scheduledClasses()->publicUpcoming()->firstOrFail();

        $this->assertNotNull($onboarding->completed_at);
        $this->assertTrue($account->allow_guest_public_booking);
        $this->assertSame(1, $account->locations()->active()->count());
        $this->assertSame(1, $account->locations()->where('is_active', false)->count());
        $this->assertFalse($placeholder->billing_activation_pending);
        $this->assertSame('Luna Pole — 2', $placeholder->name);
        $this->assertNull($trainer->user_id);
        $this->assertSame(AccountRole::Owner, $account->membershipFor($user)?->role);
        $this->assertSame(1, $account->rooms()->count());
        $this->assertSame(1, $account->activityDirections()->count());
        $this->assertSame(1, $account->classTypes()->count());
        $this->assertSame(1, $account->scheduleSeries()->count());
        $this->assertSame(0, ClassPassPlan::query()->whereBelongsTo($account)->count());
        $this->assertSame(1, $account->subscription->refresh()->billable_location_count);

        $this->get(route('public.schedule', [$account->slug, $primaryLocation->slug]))
            ->assertOk()
            ->assertSee('Pole Beginners');

        $this->post(route('public.booking.store', [$account->slug, $primaryLocation->slug]), [
            'schedule_kind' => 'group_class',
            'scheduled_class_id' => $scheduledClass->id,
            'customer_name' => 'Марія Клієнтка',
            'customer_phone' => '050 999 88 77',
        ])->assertRedirect();

        $customer = Customer::query()->whereBelongsTo($account)->where('phone', '+380509998877')->firstOrFail();
        $booking = ClassBooking::query()->whereBelongsTo($scheduledClass)->whereBelongsTo($customer)->firstOrFail();

        $this->assertNull($booking->classPassReservation);
        $firstBookingAt = Carbon::parse($onboarding->refresh()->answers['metrics']['first_booking_at']);
        $this->assertTrue($firstBookingAt->betweenIncluded($onboarding->completed_at, $onboarding->completed_at->copy()->addDays(7)));
    }

    private function preparePublicOnboarding(int $trialDays = 30): SubscriptionPriceVersion
    {
        config()->set('ladna.public_owner_onboarding_enabled', true);
        config()->set('ladna.saas_billing_v2_enabled', true);
        $this->preparePlatformIntegrations();

        $plan = SubscriptionPlan::factory()->create([
            'name' => 'Ladna Studio',
            'slug' => 'ladna-studio-public',
            'public_signup_enabled' => true,
            'requires_recurring_payment' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published()
            ->create([
                'version' => 1,
                'trial_days' => $trialDays,
            ]);
    }

    private function preparePlatformIntegrations(): void
    {
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::CloudflareTurnstile->value,
            'category' => IntegrationCategory::Authentication->value,
            'is_enabled' => true,
            'credentials' => [
                'site_key' => 'turnstile-site',
                'secret_key' => 'turnstile-secret',
            ],
        ]);
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::Turbosms->value,
            'category' => IntegrationCategory::Messaging->value,
            'is_enabled' => true,
            'credentials' => [
                'api_token' => 'turbo-token',
                'sms_sender' => 'Ladna',
            ],
        ]);
    }

    private function fakeSuccessfulGateways(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
            'https://api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'sms-1']]], 200),
        ]);
    }

    private function newOwner(): User
    {
        return User::factory()->create([
            'phone' => '+380501112233',
            'phone_verified_at' => null,
            'terms_accepted_at' => now(),
            'privacy_accepted_at' => now(),
        ]);
    }

    /**
     * @return array{User, Account}
     */
    private function ownerAfterStepOne(): array
    {
        $user = $this->newOwner();

        $this->actingAs($user)->post(route('onboarding.store', ['step' => 1]), [
            'studio_stage' => 'operating',
            'studio_name' => 'Luna Pole',
            'location_count' => 2,
        ])->assertRedirect(route('onboarding.show', ['step' => 2]));

        return [$user, $user->accounts()->firstOrFail()];
    }

    /**
     * @return array{User, Account}
     */
    private function ownerAtReviewStep(): array
    {
        [$user, $account] = $this->ownerAfterStepOne();

        $this->post(route('onboarding.store', ['step' => 2]), [
            'location_name' => 'Luna Pole Center',
            'address' => 'Київ, вул. Велика Васильківська, 20',
            'room_name' => 'Основна зала',
            'capacity' => 12,
        ])->assertRedirect(route('onboarding.show', ['step' => 3]));
        $this->post(route('onboarding.store', ['step' => 3]), [
            'teaching_mode' => 'owner',
            'trainer_name' => 'Олена Тренерка',
        ])->assertRedirect(route('onboarding.show', ['step' => 4]));
        $this->post(route('onboarding.store', ['step' => 4]), [
            'direction_name' => 'Pole Dance',
            'class_name' => 'Pole Beginners',
            'duration_minutes' => 60,
            'capacity' => 12,
        ])->assertRedirect(route('onboarding.show', ['step' => 5]));

        $firstDate = now('Europe/Kyiv')->addDay()->startOfDay();
        $this->post(route('onboarding.store', ['step' => 5]), [
            'weekday' => $firstDate->isoWeekday(),
            'start_time' => '18:00',
            'start_date' => $firstDate->toDateString(),
        ])->assertRedirect(route('onboarding.show', ['step' => 6]));

        return [$user, $account];
    }
}
