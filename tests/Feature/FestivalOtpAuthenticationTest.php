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
}
