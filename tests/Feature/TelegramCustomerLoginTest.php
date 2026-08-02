<?php

namespace Tests\Feature;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Support\CustomerAuth\TelegramCustomerLoginTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TelegramCustomerLoginTest extends TestCase
{
    use DatabaseTransactions;

    public function test_linked_telegram_customer_can_consume_a_login_link_only_once(): void
    {
        [$account, $customer, $authorization] = $this->linkedCustomer();
        $url = app(TelegramCustomerLoginTokenService::class)->issueUrl($account, $customer, $authorization);

        $this->get($url)
            ->assertRedirect(route('customer.dashboard', $account->slug));

        $this->assertAuthenticatedAs($customer, 'customer');

        Auth::guard('customer')->logout();

        $this->get($url)->assertNotFound();
        $this->assertGuest('customer');
    }

    public function test_telegram_login_link_fails_after_the_connection_is_revoked(): void
    {
        [$account, $customer, $authorization] = $this->linkedCustomer();
        $url = app(TelegramCustomerLoginTokenService::class)->issueUrl($account, $customer, $authorization);
        $authorization->update([
            'status' => TelegramChatAuthorizationStatus::Revoked->value,
            'revoked_at' => now(),
        ]);

        $this->get($url)->assertNotFound();
        $this->assertGuest('customer');
    }

    public function test_telegram_login_link_expires_after_five_minutes(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');

        try {
            [$account, $customer, $authorization] = $this->linkedCustomer();
            $url = app(TelegramCustomerLoginTokenService::class)->issueUrl($account, $customer, $authorization);

            Carbon::setTestNow(now()->addMinutes(6));

            $this->get($url)->assertForbidden();
            $this->assertGuest('customer');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{Account, Customer, TelegramChatAuthorization}
     */
    private function linkedCustomer(): array
    {
        $account = Account::factory()->create(['default_language' => 'uk']);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Telegram Customer',
            'phone' => '+380501112233',
            'phone_verified_at' => now(),
        ]);
        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'account',
            'scope_id' => $account->id,
            'profile' => TelegramBotProfile::Customer->value,
            'is_enabled' => true,
        ]);
        $authorization = TelegramChatAuthorization::factory()
            ->for($account)
            ->for($installation, 'installation')
            ->for($customer)
            ->create([
                'user_id' => null,
                'customer_id' => $customer->id,
                'profile' => TelegramBotProfile::Customer->value,
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
            ]);

        return [$account, $customer, $authorization];
    }
}
