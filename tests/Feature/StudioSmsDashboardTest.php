<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscription;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\MoneyFormatter;
use App\Support\Sms\SmsServiceSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudioSmsDashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_sees_studio_sms_credit_after_reservations_and_account_link(): void
    {
        [$account, $owner] = $this->studio();
        AccountSmsWallet::factory()->for($account)->create(['balance_cents' => 12345, 'reserved_cents' => 2345]);
        AccountSmsWallet::factory()->create(['balance_cents' => 987654]);

        $this->actingAs($owner)->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee('data-sms-account-summary', false)
            ->assertSee(MoneyFormatter::format(10000, 'UAH'))
            ->assertSee(MoneyFormatter::format(12345, 'UAH'))
            ->assertSee(route('dashboard.accounts.sms-account.show', $account), false)
            ->assertDontSee(MoneyFormatter::format(987654, 'UAH'))
            ->assertDontSee(__('app.sms_low_credit_warning'));
    }

    public function test_sms_credit_remains_account_wide_when_working_location_changes(): void
    {
        [$account, $owner] = $this->studio();
        $locations = Location::factory()->for($account)->count(2)->create();
        AccountSmsWallet::factory()->for($account)->create(['balance_cents' => 12345, 'reserved_cents' => 2345]);

        foreach ($locations as $location) {
            $response = $this->actingAs($owner)->get(route('dashboard.accounts.show', [
                'account' => $account,
                'location_context' => $location->id,
            ]))->assertOk()->assertSee(__('app.account_wide'));

            $this->assertSame(10000, $response->viewData('smsAccount')['spendableCents']);
            $this->assertSame(100, $response->viewData('smsAccount')['approximateSegments']);
        }
    }

    public function test_available_credit_triggers_warning_below_one_segment_and_recovers_at_boundary(): void
    {
        [$account, $owner] = $this->studio();
        $wallet = AccountSmsWallet::factory()->for($account)->create(['balance_cents' => 500, 'reserved_cents' => 401]);

        $this->actingAs($owner)->get(route('dashboard.accounts.show', $account))
            ->assertOk()->assertSee(__('app.sms_low_credit_warning'));

        $wallet->update(['reserved_cents' => 400]);
        $this->get(route('dashboard.accounts.show', $account))
            ->assertOk()->assertDontSee(__('app.sms_low_credit_warning'));
    }

    #[DataProvider('accountProblems')]
    public function test_owner_sees_actionable_sms_account_problems(array $attributes, string $warning): void
    {
        [$account, $owner] = $this->studio();
        AccountSmsWallet::factory()->for($account)->create($attributes);

        $this->actingAs($owner)->get(route('dashboard.accounts.show', $account))
            ->assertOk()->assertSee(__($warning, ['amount' => MoneyFormatter::format(200, 'UAH')]));
    }

    public static function accountProblems(): array
    {
        return [
            'debt' => [['outstanding_cents' => 200], 'app.sms_outstanding_debt_warning'],
            'suspended' => [['auto_top_up_suspended_at' => '2026-10-20 12:00:00'], 'app.sms_auto_top_up_suspended_warning'],
            'monthly cap' => [[
                'auto_top_up_enabled' => true,
                'auto_top_up_threshold_cents' => 1000,
                'auto_top_up_target_cents' => 5000,
                'auto_top_up_monthly_cap_cents' => 1000,
            ], 'app.sms_auto_top_up_monthly_cap_warning'],
        ];
    }

    #[DataProvider('nonPaidModes')]
    public function test_other_sms_modes_and_tariffs_do_not_show_low_credit_alerts(SmsSendingMode $mode, ?int $price): void
    {
        [$account, $owner] = $this->studio($mode, $price);

        $this->actingAs($owner)->get(route('dashboard.accounts.show', $account))
            ->assertOk()->assertSee('data-sms-account-summary', false)
            ->assertDontSee(__('app.sms_low_credit_warning'));

        $this->assertDatabaseMissing('account_sms_wallets', ['account_id' => $account->id]);
    }

    public static function nonPaidModes(): array
    {
        return [
            'disabled' => [SmsSendingMode::Disabled, 100],
            'own gateway' => [SmsSendingMode::OwnGateway, 100],
            'free' => [SmsSendingMode::LadnaService, 0],
            'no tariff' => [SmsSendingMode::LadnaService, null],
        ];
    }

    public function test_missing_wallet_shows_zero_without_creating_a_wallet(): void
    {
        [$account, $owner] = $this->studio();

        $this->actingAs($owner)->get(route('dashboard.accounts.show', $account))
            ->assertOk()->assertSee('data-sms-account-summary', false)
            ->assertSee(__('app.sms_low_credit_warning'));

        $this->assertDatabaseMissing('account_sms_wallets', ['account_id' => $account->id]);
    }

    public function test_staff_cannot_see_owner_sms_finances_and_other_owners_cannot_access_studio(): void
    {
        [$account] = $this->studio();
        foreach ([AccountRole::Manager, AccountRole::Trainer] as $role) {
            $user = User::factory()->create();
            AccountMembership::factory()->for($account)->for($user)->create(['role' => $role]);

            $this->actingAs($user)->get(route('dashboard.accounts.show', $account))
                ->assertOk()->assertDontSee('data-sms-account-summary', false);
        }

        $this->actingAs(User::factory()->create())->get(route('dashboard.accounts.show', $account))->assertForbidden();
    }

    /** @return array{Account, User} */
    private function studio(SmsSendingMode $mode = SmsSendingMode::LadnaService, ?int $price = 100): array
    {
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        AccountSubscription::factory()->for($account)
            ->for(SubscriptionPlan::factory()->create(['sms_segment_price_cents' => $price]), 'plan')->create();
        $account->customerAuthSetting()->create(['sms_sending_mode' => $mode]);
        SystemSetting::setValue(SmsServiceSettings::EnabledKey, '1');

        return [$account, $owner];
    }
}
