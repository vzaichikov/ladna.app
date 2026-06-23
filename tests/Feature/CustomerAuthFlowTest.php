<?php

namespace Tests\Feature;

use App\Enums\CustomerOtpSenderScope;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerAuthSetting;
use App\Models\CustomerRememberToken;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\CustomerAuth\CustomerOtpService;
use App\Support\CustomerAuth\CustomerRememberTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerAuthFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_login_only_shows_configured_methods(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'en',
            'slug' => 'customer-auth-methods-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'allow_email_password' => true,
            'allow_otp' => true,
            'allow_google' => true,
        ]);

        $this->get(route('customer.studio.login', $account->slug))
            ->assertOk()
            ->assertSee('Email', false)
            ->assertDontSee('Google login')
            ->assertDontSee('Phone + OTP');
    }

    public function test_customer_login_shows_ukrainian_password_help(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-password-help-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'allow_email_password' => true,
            'allow_otp' => false,
            'allow_google' => false,
        ]);

        $this->get(route('customer.studio.login', $account->slug))
            ->assertOk()
            ->assertSee('Придумайте пароль щонайменше з 6 символів або цифр.', false);
    }

    public function test_customer_email_password_requires_at_least_six_characters_in_ukrainian(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'uk',
            'slug' => 'customer-password-min-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'allow_email_password' => true,
            'allow_otp' => false,
            'allow_google' => false,
        ]);

        $this->withSession(['locale' => 'uk'])
            ->post(route('customer.email.login', $account->slug), [
                'email' => 'short-password@example.com',
                'password' => '12345',
            ])
            ->assertSessionHasErrors([
                'password' => 'Пароль має містити щонайменше 6 символів.',
            ]);
    }

    public function test_customer_profile_uses_ukrainian_phone_mask_messages(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'uk',
            'country_code' => 'UA',
            'slug' => 'profile-phone-mask-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Victor',
            'phone' => null,
        ]);

        $this->actingAs($customer, 'customer')
            ->get(route('customer.profile.complete', $account->slug))
            ->assertOk()
            ->assertSee('Введіть повний номер телефону.', false)
            ->assertSee('Пошук країни...', false)
            ->assertSee('Країни не знайдено.', false)
            ->assertSee('Номер телефону заповнено коректно.', false)
            ->assertSee('Новий пароль', false)
            ->assertSee('Підтвердження нового пароля', false)
            ->assertSee('Залиште порожнім, якщо не хочете змінювати пароль. Новий пароль має містити щонайменше 6 символів.', false)
            ->assertDontSee('Please enter a complete phone number.')
            ->assertDontSee('Looks good');
    }

    public function test_customer_profile_rejects_incomplete_phone_in_ukrainian(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'uk',
            'country_code' => 'UA',
            'slug' => 'profile-phone-invalid-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Victor',
            'phone' => null,
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->put(route('customer.profile.update', $account->slug), [
                'name' => 'Victor Zaichikov',
                'phone' => '+38063',
                'email' => 'victor.profile@example.com',
            ])
            ->assertSessionHasErrors([
                'phone' => 'Введіть коректний номер телефону.',
            ]);
    }

    public function test_customer_otp_does_not_send_for_incomplete_phone(): void
    {
        app()->setLocale('uk');

        $account = Account::factory()->create([
            'default_language' => 'uk',
            'country_code' => 'UA',
            'slug' => 'otp-phone-invalid-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        $result = app(CustomerOtpService::class)->send($account, '+38063');

        $this->assertFalse($result->ok);
        $this->assertSame('Введіть коректний номер телефону.', $result->message);
    }

    public function test_customer_can_change_password_from_profile(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'uk',
            'country_code' => 'UA',
            'slug' => 'profile-password-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Victor',
            'phone' => '+380501112233',
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($customer, 'customer')
            ->put(route('customer.profile.update', $account->slug), [
                'name' => 'Victor Zaichikov',
                'phone' => '+380501112233',
                'email' => 'victor.password@example.com',
                'password' => 'new-pass',
                'password_confirmation' => 'new-pass',
            ])
            ->assertRedirect(route('customer.dashboard', $account->slug));

        $customer->refresh();

        $this->assertSame('Victor Zaichikov', $customer->name);
        $this->assertTrue(Hash::check('new-pass', $customer->password));
    }

    public function test_customer_profile_password_confirmation_is_ukrainian(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'uk',
            'country_code' => 'UA',
            'slug' => 'profile-password-confirmation-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Victor',
            'phone' => '+380501112233',
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($customer, 'customer')
            ->withSession(['locale' => 'uk'])
            ->put(route('customer.profile.update', $account->slug), [
                'name' => 'Victor Zaichikov',
                'phone' => '+380501112233',
                'email' => 'victor.confirmation@example.com',
                'password' => 'new-pass',
                'password_confirmation' => 'another-pass',
            ])
            ->assertSessionHasErrors([
                'password' => 'Підтвердження пароля не збігається.',
            ]);

        $this->assertTrue(Hash::check('old-password', $customer->fresh()->password));
    }

    public function test_platform_admin_can_update_customer_auth_settings_and_owner_cannot(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($platformAdmin)
            ->put(route('platform.accounts.customer-auth.update', $account), [
                'allow_email_password' => '1',
                'allow_otp' => '1',
                'allow_google' => '0',
                'otp_sender_scope' => CustomerOtpSenderScope::Account->value,
                'otp_provider' => 'smsclub',
            ])
            ->assertRedirect(route('platform.accounts.customer-auth.edit', $account));

        $setting = $account->customerAuthSetting()->firstOrFail();
        $this->assertTrue($setting->allow_otp);
        $this->assertSame(CustomerOtpSenderScope::Account, $setting->otp_sender_scope);
        $this->assertSame('smsclub', $setting->otp_provider);

        $this->actingAs($owner)
            ->get(route('platform.accounts.customer-auth.edit', $account))
            ->assertForbidden();
    }

    public function test_customer_can_login_with_otp_and_is_forced_to_complete_profile(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'en',
            'country_code' => 'UA',
            'slug' => 'otp-login-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'allow_email_password' => false,
            'allow_otp' => true,
            'allow_google' => false,
            'otp_sender_scope' => CustomerOtpSenderScope::Platform->value,
            'otp_provider' => 'turbosms',
        ]);

        $this->platformIntegration('cloudflare_turnstile', IntegrationCategory::Authentication->value, [
            'site_key' => 'test-site-key',
            'secret_key' => 'test-secret-key',
        ]);

        $this->platformIntegration('turbosms', IntegrationCategory::Messaging->value, [
            'api_token' => 'turbo-token',
            'sms_sender' => 'Ladna',
        ]);

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
            'api.turbosms.ua/*' => Http::response(['response_result' => [['message_id' => 'otp-1']]]),
        ]);

        $this->post(route('customer.otp.send', $account->slug), [
            'phone' => '0501112233',
            'cf-turnstile-response' => 'turnstile-token',
        ])->assertRedirect(route('customer.otp.challenge', $account->slug));

        $this->post(route('customer.otp.verify', $account->slug), [
            'phone' => '+380501112233',
            'code' => '123456',
        ])->assertRedirect(route('customer.profile.complete', $account->slug));

        $customer = Customer::where('account_id', $account->id)->where('phone', '+380501112233')->firstOrFail();

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertNotNull($customer->phone_verified_at);
        $this->assertTrue(CustomerRememberToken::whereBelongsTo($customer)->exists());
    }

    public function test_email_login_does_not_allow_claiming_customer_with_blank_password(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'en',
            'slug' => 'email-blank-password-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'allow_email_password' => true,
        ]);

        Customer::factory()->for($account)->create([
            'email' => 'blank-password@example.com',
            'password' => null,
        ]);

        $this->post(route('customer.email.login', $account->slug), [
            'email' => 'blank-password@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    }

    public function test_google_login_merges_existing_verified_email_customer(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'en',
            'slug' => 'google-merge-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        CustomerAuthSetting::create([
            'account_id' => $account->id,
            'allow_email_password' => false,
            'allow_google' => true,
        ]);

        $customer = Customer::factory()->for($account)->create([
            'name' => 'Jane Client',
            'email' => 'jane.google@example.com',
            'phone' => '+380501234567',
            'password' => Hash::make('password'),
            'google_id' => null,
        ]);

        $this->platformIntegration('google_oauth', IntegrationCategory::Authentication->value, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
        ]);

        $redirect = $this->get(route('customer.google.redirect', $account->slug))
            ->assertRedirect()
            ->headers->get('Location');

        parse_str(parse_url((string) $redirect, PHP_URL_QUERY) ?: '', $query);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-subject-1',
                'email' => 'jane.google@example.com',
                'email_verified' => true,
                'name' => 'Jane Google',
            ]),
        ]);

        $this->get(route('customer.google.callback', [
            'state' => $query['state'],
            'code' => 'auth-code',
        ]))->assertRedirect(route('customer.dashboard', $account->slug));

        $customer->refresh();

        $this->assertSame('google-subject-1', $customer->google_id);
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_customer_remember_cookie_slides_expiration_on_visit(): void
    {
        $account = Account::factory()->create([
            'slug' => 'sliding-remember-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $customer = Customer::factory()->for($account)->create([
            'phone' => '+380501000002',
        ]);
        $selector = str_repeat('a', 32);
        $token = 'plain-token-for-sliding-cookie';

        $rememberToken = $customer->rememberTokens()->create([
            'selector' => $selector,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(10),
        ]);

        $request = Request::create(route('customer.dashboard', $account->slug));
        $request->cookies->set('ladna_customer_remember', $selector.'|'.$token);

        $rememberedCustomer = app(CustomerRememberTokenService::class)->authenticateFromCookie($request);
        $freshToken = $rememberToken->fresh();

        $this->assertTrue($rememberedCustomer->is($customer));
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertTrue($freshToken->expires_at->greaterThan(now()->addDays(89)));
        $this->assertNotNull($freshToken->last_used_at);
        $this->assertNotNull(Cookie::queued('ladna_customer_remember'));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function platformIntegration(string $provider, string $category, array $credentials): IntegrationSetting
    {
        return IntegrationSetting::create([
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
            'provider' => $provider,
            'category' => $category,
            'is_enabled' => true,
            'credentials' => $credentials,
        ]);
    }
}
