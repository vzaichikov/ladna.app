<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceMutationAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_finance_mutations_enforce_the_permission_matrix_and_immediate_revocation(): void
    {
        $owner = User::factory()->create();
        $cashManager = User::factory()->create();
        $reporter = User::factory()->create();
        $trainer = User::factory()->create();
        $account = Account::factory()->create([
            'default_currency' => 'UAH',
            'timezone' => 'UTC',
        ]);
        $account->addOwner($owner);
        $account->users()->syncWithoutDetaching([
            $cashManager->id => [
                'role' => AccountRole::Trainer->value,
                'permissions' => [StudioPermission::ManageStudioCashflow->value],
            ],
            $reporter->id => [
                'role' => AccountRole::Trainer->value,
                'permissions' => [StudioPermission::ViewStudioFinancialReports->value],
            ],
            $trainer->id => [
                'role' => AccountRole::Trainer->value,
                'permissions' => null,
            ],
        ]);
        $location = Location::factory()->for($account)->create();
        $category = ExpenseCategory::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $purchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($location)
            ->create([
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashBooking,
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 10000,
                'currency' => 'UAH',
                'paid_at' => now(),
            ]);

        $this->actingAs($cashManager)
            ->post(route('dashboard.accounts.cash-entries.store', $account), $this->cashEntryPayload($location))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
        $this->actingAs($cashManager)
            ->post(route('dashboard.accounts.cashbox-reconciliations.store', $account), [
                'location_id' => $location->id,
                'actual_amount' => '100.00',
                'currency' => 'UAH',
                'reason' => 'Cash manager physical count.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
        $this->actingAs($cashManager)
            ->post(route('dashboard.accounts.expenses.store', $account), $this->expensePayload($category, $location))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, $account->studioCashEntries()->count());
        $this->assertSame(1, $account->cashboxReconciliations()->count());
        $this->assertSame(1, $account->studioExpenses()->count());

        $this->actingAs($cashManager)
            ->post(route('dashboard.accounts.finance-epochs.store', $account), $this->epochPayload($location))
            ->assertForbidden();
        $this->actingAs($cashManager)
            ->post(route('dashboard.accounts.payments.corrections.store', [$account, $purchase]), $this->correctionPayload($location))
            ->assertForbidden();
        $this->actingAs($cashManager)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $this->refundPayload())
            ->assertForbidden();

        foreach ([$reporter, $trainer] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)
                ->post(route('dashboard.accounts.cash-entries.store', $account), $this->cashEntryPayload($location))
                ->assertForbidden();
            $this->actingAs($unauthorizedUser)
                ->post(route('dashboard.accounts.cashbox-reconciliations.store', $account), [
                    'location_id' => $location->id,
                    'actual_amount' => '100.00',
                    'reason' => 'Unauthorized reconciliation.',
                    'idempotency_key' => (string) Str::uuid(),
                ])
                ->assertForbidden();
            $this->actingAs($unauthorizedUser)
                ->post(route('dashboard.accounts.expenses.store', $account), $this->expensePayload($category, $location))
                ->assertForbidden();
            $this->actingAs($unauthorizedUser)
                ->post(route('dashboard.accounts.finance-epochs.store', $account), $this->epochPayload($location))
                ->assertForbidden();
        }

        $account->memberships()
            ->whereBelongsTo($cashManager)
            ->update(['permissions' => []]);

        $this->actingAs($cashManager->fresh())
            ->post(route('dashboard.accounts.expenses.store', $account), $this->expensePayload($category, $location))
            ->assertForbidden();

        $this->assertSame(1, $account->studioCashEntries()->count());
        $this->assertSame(1, $account->cashboxReconciliations()->count());
        $this->assertSame(1, $account->studioExpenses()->count());
        $this->assertSame(0, $purchase->corrections()->count());
        $this->assertSame(0, CustomerPurchaseRefund::query()->count());
        $this->assertSame(0, $account->financeEpochs()->where('is_legacy', false)->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function cashEntryPayload(Location $location): array
    {
        return [
            'direction' => StudioCashEntry::DirectionIn,
            'location_id' => $location->id,
            'amount' => '100.00',
            'occurred_at' => now()->format('Y-m-d\TH:i'),
            'reason' => 'Authorized cash deposit.',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expensePayload(ExpenseCategory $category, Location $location): array
    {
        return [
            'expense_category_id' => $category->id,
            'amount' => '25.00',
            'occurred_at' => now()->format('Y-m-d\TH:i'),
            'reason' => 'Authorized operational expense.',
            'payment_method' => StudioExpense::PaymentMethodBankCard,
            'expense_location_id' => $location->id,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function epochPayload(Location $location): array
    {
        return [
            'approval' => 'approve',
            'cashboxes' => [[
                'location_id' => $location->id,
                'currency' => 'UAH',
                'actual_amount' => '100.00',
            ]],
            'reason' => 'Unauthorized epoch restart attempt.',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function correctionPayload(Location $location): array
    {
        return [
            'location_id' => $location->id,
            'amount' => '100.00',
            'paid_at' => now()->format('Y-m-d\TH:i'),
            'reason' => 'Unauthorized correction attempt.',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refundPayload(): array
    {
        return [
            'amount' => '10.00',
            'method' => CustomerPurchaseRefund::MethodCashless,
            'reason' => 'Unauthorized refund attempt.',
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
