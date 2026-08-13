<?php

namespace Tests\Feature;

use App\Enums\FestivalPortalRole;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerAuthSetting;
use App\Models\CustomerOtpChallenge;
use App\Models\FestivalOtpChallenge;
use App\Models\FestivalPortalUser;
use App\Models\IntegrationSetting;
use App\Support\CustomerAuth\CustomerOtpService;
use App\Support\Festivals\FestivalOtpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FestivalOtpAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verified_participant_otp_creates_only_a_festival_profile_and_hides_the_code_from_logs(): void
    {
        $account = $this->otpAccount();
        $customerCount = Customer::query()->count();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
            'api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'festival-otp-1']]]),
        ]);

        $this->post(route('festival.login.otp.send', $account->slug), [
            'phone' => '0501112233',
            'cf-turnstile-response' => 'turnstile-token',
        ])->assertRedirect(route('festival.login.otp.challenge', $account->slug));

        $challenge = FestivalOtpChallenge::query()->whereBelongsTo($account)->firstOrFail();
        $delivery = $account->smsDeliveries()->sole();
        $this->assertSame(FestivalPortalRole::Registrant, $challenge->role);
        $this->assertSame('+380501112233', $challenge->phone);
        $this->assertSame(SmsDeliveryPurpose::FestivalOtp, $delivery->purpose);
        $this->assertNull($delivery->message_preview);
        $this->assertSame($challenge->getMorphClass(), $delivery->source_type);
        $this->assertSame($challenge->id, $delivery->source_id);

        $this->post(route('festival.login.otp.verify', $account->slug), [
            'phone' => '+380501112233',
            'code' => '123456',
        ])->assertRedirect(route('festival.portal.dashboard', $account->slug));

        $portalUser = FestivalPortalUser::query()->whereBelongsTo($account)->where('phone_normalized', '+380501112233')->firstOrFail();
        $this->assertSame(FestivalPortalRole::Registrant, $portalUser->role);
        $this->assertNotNull($portalUser->phone_verified_at);
        $this->assertNotNull($challenge->refresh()->consumed_at);
        $this->assertSame($customerCount, Customer::query()->count());
        $this->assertAuthenticatedAs($portalUser, 'festival');

        $this->get(route('festival.portal.profile.edit', $account->slug))
            ->assertOk()
            ->assertDontSee('data-festival-profile-phone-step', false)
            ->assertSee(__('app.festival_profile_step_label', ['current' => 3, 'total' => 3]));
    }

    public function test_participant_login_mirrors_customer_method_priority_while_judge_login_stays_unchanged(): void
    {
        $account = $this->otpAccount();
        IntegrationSetting::create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => 'google_oauth',
            'category' => IntegrationCategory::Authentication->value,
            'is_enabled' => true,
            'credentials' => [
                'client_id' => 'festival-google-client',
                'client_secret' => 'festival-google-secret',
            ],
        ]);

        $this->get(route('festival.login', $account->slug))
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('data-active-method="phone"', false)
            ->assertSeeInOrder([
                'data-customer-auth-tab="phone"',
                'data-customer-auth-tab="email"',
                'data-customer-auth-panel="phone"',
                'data-customer-auth-panel="email"',
                __('app.google_sign_in'),
            ], false);

        $this->get(route('festival.judge.login', $account->slug))
            ->assertOk()
            ->assertDontSee('data-customer-auth-tabs', false)
            ->assertDontSee('data-customer-auth-tab="phone"', false)
            ->assertDontSee(__('app.festival_profile_step_label', ['current' => 1, 'total' => 3]));
    }

    public function test_unknown_judge_cannot_self_register_with_a_verified_otp(): void
    {
        $account = $this->otpAccount();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
            'api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'festival-judge-otp']]]),
        ]);

        $this->post(route('festival.judge.login.otp.send', $account->slug), [
            'phone' => '0502223344',
            'cf-turnstile-response' => 'turnstile-token',
        ])->assertRedirect(route('festival.judge.login.otp.challenge', $account->slug));

        $this->post(route('festival.judge.login.otp.verify', $account->slug), [
            'phone' => '+380502223344',
            'code' => '123456',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('festival_portal_users', [
            'account_id' => $account->id,
            'phone_normalized' => '+380502223344',
        ]);
        $this->assertGuest('festival');
    }

    public function test_guest_otp_is_role_bound_even_when_a_registrant_uses_the_same_phone(): void
    {
        $account = $this->otpAccount();
        FestivalPortalUser::factory()->for($account)->create([
            'phone' => '+380503334455',
            'phone_normalized' => '+380503334455',
        ]);
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
            'api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'festival-guest-otp']]]),
        ]);

        $this->post(route('festival.guest.login.otp.send', $account->slug), [
            'phone' => '0503334455',
            'cf-turnstile-response' => 'turnstile-token',
        ])->assertRedirect(route('festival.guest.login.otp.challenge', $account->slug));

        $challenge = FestivalOtpChallenge::query()->whereBelongsTo($account)->latest('id')->firstOrFail();
        $this->assertSame(FestivalPortalRole::Guest, $challenge->role);
        $this->post(route('festival.guest.login.otp.verify', $account->slug), [
            'phone' => '+380503334455',
            'code' => '123456',
        ])->assertRedirect(route('festival.portal.guest.dashboard', $account->slug));

        $guest = FestivalPortalUser::query()->whereBelongsTo($account)->forRole(FestivalPortalRole::Guest)->sole();
        $this->assertSame('+380503334455', $guest->phone_normalized);
        $this->assertAuthenticatedAs($guest, 'festival');
    }

    public function test_festival_and_customer_otp_challenges_cannot_consume_each_other(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $festivalChallenge = FestivalOtpChallenge::factory()->for($account)->create([
            'role' => FestivalPortalRole::Registrant,
            'phone' => '+380501234567',
            'code_hash' => Hash::make('111111'),
        ]);
        $customerChallenge = CustomerOtpChallenge::query()->create([
            'account_id' => $account->id,
            'phone' => '+380501234567',
            'code_hash' => Hash::make('222222'),
            'expires_at' => now()->addMinutes(10),
            'resend_available_at' => now()->addMinute(),
            'attempts' => 0,
            'send_count' => 1,
            'last_sent_at' => now(),
            'provider_scope' => 'own_gateway',
            'provider' => 'turbosms',
        ]);

        $festivalWithCustomerCode = app(FestivalOtpService::class)->verify(
            $account,
            FestivalPortalRole::Registrant,
            '+380501234567',
            '222222',
        );
        $customerWithFestivalCode = app(CustomerOtpService::class)->verify($account, '+380501234567', '111111');

        $this->assertFalse($festivalWithCustomerCode->ok);
        $this->assertFalse($customerWithFestivalCode->ok);
        $this->assertNull($festivalChallenge->refresh()->consumed_at);
        $this->assertNull($customerChallenge->refresh()->consumed_at);
        $this->assertSame(1, $festivalChallenge->attempts);
        $this->assertSame(1, $customerChallenge->attempts);
    }

    public function test_festival_otp_is_account_and_role_bound_one_time_only(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $challenge = FestivalOtpChallenge::factory()->for($account)->create([
            'role' => FestivalPortalRole::Judge,
            'phone' => '+380509876543',
            'code_hash' => Hash::make('333333'),
        ]);

        $wrongRole = app(FestivalOtpService::class)->verify($account, FestivalPortalRole::Registrant, '+380509876543', '333333');
        $wrongAccount = app(FestivalOtpService::class)->verify($otherAccount, FestivalPortalRole::Judge, '+380509876543', '333333');
        $correct = app(FestivalOtpService::class)->verify($account, FestivalPortalRole::Judge, '+380509876543', '333333');
        $replay = app(FestivalOtpService::class)->verify($account, FestivalPortalRole::Judge, '+380509876543', '333333');

        $this->assertFalse($wrongRole->ok);
        $this->assertFalse($wrongAccount->ok);
        $this->assertTrue($correct->ok);
        $this->assertFalse($replay->ok);
        $this->assertNotNull($challenge->refresh()->consumed_at);
    }

    public function test_otp_is_hidden_unless_all_account_sms_and_turnstile_prerequisites_are_ready(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'allow_otp' => true,
            'sms_sending_mode' => SmsSendingMode::Disabled->value,
        ]);

        $this->get(route('festival.login', $account->slug))
            ->assertOk()
            ->assertDontSee('cf-turnstile', false)
            ->assertDontSee('name="phone"', false);

        $this->post(route('festival.login.otp.send', $account->slug), [
            'phone' => '0501112233',
            'cf-turnstile-response' => 'token',
        ])->assertNotFound();
    }

    public function test_profile_phone_is_replaced_only_after_festival_otp_verification(): void
    {
        $account = $this->otpAccount();
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'phone' => '+380501112233',
            'phone_normalized' => '+380501112233',
            'phone_verified_at' => now(),
        ]);
        Http::fake([
            'api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'festival-profile-otp']]]),
        ]);

        $this->actingAs($portalUser, 'festival')->put(route('festival.portal.profile.update', $account->slug), [
            'registrant_type' => 'coach',
            'first_name' => $portalUser->first_name,
            'last_name' => $portalUser->last_name,
            'email' => $portalUser->email,
            'phone' => '0509998877',
            'city' => $portalUser->city,
            'studio_name' => $portalUser->studio_name,
            'locale' => 'uk',
        ])->assertRedirect(route('festival.portal.profile.edit', $account->slug));

        $this->assertSame('+380501112233', $portalUser->refresh()->phone_normalized);
        $this->get(route('festival.portal.profile.edit', $account->slug))
            ->assertOk()
            ->assertSee('data-festival-profile-phone-step', false)
            ->assertSee('value="+380509998877"', false)
            ->assertDontSee('name="city"', false);

        $this->post(route('festival.portal.profile.phone.send', $account->slug), [
            'phone' => '+380509998877',
        ])->assertRedirect();
        $this->post(route('festival.portal.profile.phone.verify', $account->slug), [
            'phone' => '+380509998877',
            'code' => '123456',
        ])->assertRedirect(route('festival.portal.dashboard', $account->slug));

        $this->assertSame('+380509998877', $portalUser->refresh()->phone_normalized);
        $this->assertNotNull($portalUser->phone_verified_at);
    }

    public function test_email_participant_verifies_phone_before_completing_the_remaining_profile(): void
    {
        $account = $this->otpAccount();
        Http::fake([
            'api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'festival-first-profile-otp']]]),
        ]);

        $this->post(route('festival.login.email', $account->slug), [
            'email' => 'staged-participant@example.com',
            'password' => 'secret1',
        ])->assertRedirect(route('festival.portal.dashboard', $account->slug));

        $portalUser = FestivalPortalUser::query()
            ->whereBelongsTo($account)
            ->where('email_normalized', 'staged-participant@example.com')
            ->firstOrFail();

        $this->get(route('festival.portal.dashboard', $account->slug))
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug));

        $this->get(route('festival.portal.profile.edit', $account->slug))
            ->assertOk()
            ->assertSee('data-festival-profile-phone-step', false)
            ->assertSee(__('app.festival_profile_step_label', ['current' => 2, 'total' => 3]))
            ->assertSee('name="phone"', false)
            ->assertDontSee('name="first_name"', false)
            ->assertDontSee('name="telegram_contact"', false);

        $this->post(route('festival.portal.profile.phone.send', $account->slug), [
            'phone' => '0507776655',
        ])->assertRedirect(route('festival.portal.profile.edit', $account->slug))
            ->assertSessionHasNoErrors();

        $portalUser->refresh();
        $this->assertNull($portalUser->phone);
        $this->assertNull($portalUser->phone_normalized);
        $this->assertNull($portalUser->phone_verified_at);
        $this->assertDatabaseHas('festival_otp_challenges', [
            'account_id' => $account->id,
            'role' => FestivalPortalRole::Registrant->value,
            'phone' => '+380507776655',
        ]);
        $this->assertDatabaseCount('sms_deliveries', 1);

        $this->get(route('festival.portal.profile.edit', $account->slug))
            ->assertOk()
            ->assertSee('festival-profile-phone-verify', false);

        $this->post(route('festival.portal.profile.phone.verify', $account->slug), [
            'phone' => '+380507776655',
            'code' => '000000',
        ])->assertSessionHasErrors('code');

        $this->assertNull($portalUser->refresh()->phone_normalized);
        $this->assertNull($portalUser->phone_verified_at);
        $this->get(route('festival.portal.dashboard', $account->slug))
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug));

        $this->post(route('festival.portal.profile.phone.verify', $account->slug), [
            'phone' => '+380507776655',
            'code' => '123456',
        ])->assertRedirect(route('festival.portal.profile.edit', $account->slug));

        $this->assertSame('+380507776655', $portalUser->refresh()->phone_normalized);
        $this->assertNotNull($portalUser->phone_verified_at);

        $this->get(route('festival.portal.profile.edit', $account->slug))
            ->assertOk()
            ->assertDontSee('data-festival-profile-phone-step', false)
            ->assertSee(__('app.festival_profile_step_label', ['current' => 3, 'total' => 3]))
            ->assertSee('name="first_name"', false)
            ->assertSee('name="telegram_contact"', false);

        $profileResponse = $this->put(route('festival.portal.profile.update', $account->slug), $this->profilePayload($portalUser, [
            'first_name' => 'Updated participant',
            'last_name' => 'Profile',
            'phone' => '+380507776655',
            'city' => 'Kyiv',
            'studio_name' => 'Festival Studio',
            'telegram_contact' => 't.me/festival_participant',
        ]));
        $this->assertSame(route('festival.portal.dashboard', $account->slug), $profileResponse->headers->get('Location'));

        $this->assertSame('Updated participant', $portalUser->refresh()->first_name);
        $this->assertSame('t.me/festival_participant', $portalUser->telegram_contact);
        $this->get(route('festival.portal.dashboard', $account->slug))->assertOk();
    }

    public function test_unverified_participant_cannot_bypass_the_phone_step_with_a_full_profile_request(): void
    {
        $account = $this->otpAccount();
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'phone' => null,
            'phone_normalized' => null,
            'phone_verified_at' => null,
        ]);
        Http::fake();

        $originalFirstName = $portalUser->first_name;

        $this->actingAs($portalUser, 'festival')
            ->put(route('festival.portal.profile.update', $account->slug), $this->profilePayload($portalUser, [
                'first_name' => 'Should not save yet',
                'phone' => '0503332211',
            ]))
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug))
            ->assertSessionHasNoErrors();

        $this->assertSame($originalFirstName, $portalUser->refresh()->first_name);
        $this->assertNull($portalUser->phone_normalized);
        $this->assertDatabaseCount('festival_otp_challenges', 0);
        $this->assertDatabaseCount('sms_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_expired_first_profile_phone_otp_keeps_the_phone_pending_and_blocks_cabinet(): void
    {
        $account = $this->otpAccount();
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'phone' => null,
            'phone_normalized' => null,
            'phone_verified_at' => null,
        ]);

        Http::fake([
            'api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'festival-expired-profile-otp']]]),
        ]);

        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.profile.phone.send', $account->slug), [
                'phone' => '0503332211',
            ])
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug))
            ->assertSessionHasNoErrors();

        FestivalOtpChallenge::query()
            ->whereBelongsTo($account)
            ->where('phone', '+380503332211')
            ->update(['expires_at' => now()->subMinute()]);

        $this->post(route('festival.portal.profile.phone.verify', $account->slug), [
            'phone' => '+380503332211',
            'code' => '123456',
        ])->assertSessionHasErrors([
            'code' => __('app.customer_otp_expired'),
        ]);

        $this->assertNull($portalUser->refresh()->phone_normalized);
        $this->assertNull($portalUser->phone_verified_at);
        $this->get(route('festival.portal.dashboard', $account->slug))
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug));
    }

    public function test_profile_otp_delivery_failure_keeps_saved_profile_pending_and_blocks_cabinet(): void
    {
        $account = $this->otpAccount();
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'phone' => null,
            'phone_normalized' => null,
            'phone_verified_at' => null,
        ]);
        Http::fake([
            'api.turbosms.ua/*' => Http::response('Rejected', 422),
        ]);

        $originalCity = $portalUser->city;

        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.profile.phone.send', $account->slug), [
                'phone' => '0504443322',
            ])
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug))
            ->assertSessionHasErrors('phone');

        $portalUser->refresh();
        $this->assertSame($originalCity, $portalUser->city);
        $this->assertNull($portalUser->phone_normalized);
        $this->assertNull($portalUser->phone_verified_at);
        $this->assertDatabaseCount('festival_otp_challenges', 0);
        $this->assertDatabaseHas('sms_deliveries', [
            'account_id' => $account->id,
            'recipient_phone' => '+380504443322',
            'status' => 'failed',
        ]);
        $this->get(route('festival.portal.profile.edit', $account->slug))
            ->assertOk()
            ->assertSee('value="+380504443322"', false)
            ->assertSee('data-festival-profile-phone-step', false);
        $this->get(route('festival.portal.dashboard', $account->slug))
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug));
    }

    public function test_duplicate_first_profile_phone_is_rejected_without_sending_otp(): void
    {
        $account = $this->otpAccount();
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'phone' => null,
            'phone_normalized' => null,
            'phone_verified_at' => null,
        ]);
        FestivalPortalUser::factory()->for($account)->create([
            'phone' => '+380505554433',
            'phone_normalized' => '+380505554433',
        ]);
        Http::fake();

        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.profile.phone.send', $account->slug), [
                'phone' => '0505554433',
            ])
            ->assertSessionHasErrors('phone');

        $this->assertNull($portalUser->refresh()->phone_normalized);
        $this->assertDatabaseCount('festival_otp_challenges', 0);
        $this->assertDatabaseCount('sms_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_turnstile_failure_prevents_festival_otp_delivery(): void
    {
        $account = $this->otpAccount();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false]),
        ]);

        $this->post(route('festival.login.otp.send', $account->slug), [
            'phone' => '0501112233',
            'cf-turnstile-response' => 'invalid-token',
        ])->assertSessionHasErrors('cf-turnstile-response');

        $this->assertDatabaseCount('festival_otp_challenges', 0);
        $this->assertDatabaseCount('sms_deliveries', 0);
    }

    private function otpAccount(): Account
    {
        $account = Account::factory()->create([
            'enable_festivals' => true,
            'default_language' => 'en',
            'country_code' => 'UA',
        ]);
        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'allow_otp' => true,
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'turbosms',
        ]);
        IntegrationSetting::create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => 'cloudflare_turnstile',
            'category' => IntegrationCategory::Authentication->value,
            'is_enabled' => true,
            'credentials' => [
                'site_key' => 'turnstile-site',
                'secret_key' => 'turnstile-secret',
            ],
        ]);
        IntegrationSetting::create([
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => $account->id,
            'account_id' => $account->id,
            'provider' => 'turbosms',
            'category' => IntegrationCategory::Messaging->value,
            'is_enabled' => true,
            'credentials' => [
                'api_token' => 'turbosms-token',
                'sms_sender' => 'Ladna',
            ],
        ]);

        return $account;
    }

    /** @param array<string, mixed> $overrides */
    private function profilePayload(FestivalPortalUser $portalUser, array $overrides = []): array
    {
        return array_merge([
            'registrant_type' => 'coach',
            'first_name' => $portalUser->first_name,
            'last_name' => $portalUser->last_name,
            'email' => $portalUser->email,
            'phone' => $portalUser->phone,
            'city' => $portalUser->city,
            'studio_name' => $portalUser->studio_name,
            'locale' => 'uk',
        ], $overrides);
    }
}
