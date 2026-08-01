<?php

namespace Tests\Feature;

use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\PayrollRun;
use App\Models\StudioExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FinanceDangerousOperationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_sensitive_finance_form_uses_the_shared_confirmation_contract(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'default_currency' => 'UAH',
            'timezone' => 'UTC',
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($location)
            ->create([
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashBooking,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 5000,
                'currency' => 'UAH',
                'paid_at' => now(),
            ]);
        $category = ExpenseCategory::factory()->for($account)->create();
        StudioExpense::factory()
            ->for($account)
            ->for($category, 'category')
            ->for($location)
            ->create();
        PayrollRun::factory()->for($account)->create([
            'period_starts_on' => now()->subMonthNoOverflow()->startOfMonth(),
            'period_ends_on' => now()->subMonthNoOverflow()->endOfMonth(),
            'status' => PayrollRun::StatusVoided,
            'voided_by_user_id' => $owner->id,
            'voided_at' => now(),
            'void_reason' => 'Voided run available for replacement.',
        ]);
        PayrollRun::factory()->for($account)->create([
            'period_starts_on' => now()->subMonthsNoOverflow(2)->startOfMonth(),
            'period_ends_on' => now()->subMonthsNoOverflow(2)->endOfMonth(),
        ]);

        $payments = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', $account))
            ->assertOk();
        foreach ([
            'confirm_payment_correction_title',
            'confirm_payment_refund_title',
        ] as $translationKey) {
            $payments->assertSee(__('app.'.$translationKey));
        }
        $payments->assertSee('name="idempotency_key"', false);

        $cash = $this->actingAs($owner)
            ->get(route('dashboard.accounts.cash.index', $account))
            ->assertOk();
        foreach ([
            'cash_entry_confirmation_title',
            'start_finance_epoch_confirmation_title',
            'cashbox_reconciliation_confirmation_title',
        ] as $translationKey) {
            $cash->assertSee(__('app.'.$translationKey));
        }
        $cash->assertSee('data-confirm-phrase="approve"', false);
        $cash->assertSee('data-confirm-approval-output', false);

        $expenses = $this->actingAs($owner)
            ->get(route('dashboard.accounts.expenses.index', $account))
            ->assertOk();
        foreach ([
            'confirm_expense_create_title',
            'confirm_expense_void_title',
        ] as $translationKey) {
            $expenses->assertSee(__('app.'.$translationKey));
        }
        $expenses->assertSee('name="idempotency_key"', false);

        $payroll = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payroll.index', $account))
            ->assertOk();
        foreach ([
            'confirm_payroll_cadence_title',
            'confirm_payroll_close_title',
            'void_payroll_run',
            'confirm_payroll_replace_title',
        ] as $translationKey) {
            $payroll->assertSee(__('app.'.$translationKey));
        }

        $this->assertGreaterThanOrEqual(2, substr_count($payments->getContent(), 'data-confirm-action'));
        $this->assertGreaterThanOrEqual(3, substr_count($cash->getContent(), 'data-confirm-action'));
        $this->assertGreaterThanOrEqual(2, substr_count($expenses->getContent(), 'data-confirm-action'));
        $this->assertGreaterThanOrEqual(4, substr_count($payroll->getContent(), 'data-confirm-action'));
    }
}
