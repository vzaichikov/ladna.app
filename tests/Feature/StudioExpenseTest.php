<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudioExpenseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_expense_categories_can_be_created_renamed_deactivated_and_reactivated_without_deletion(): void
    {
        [$owner, $account] = $this->ownerContext();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.expense-categories.store', $account), [
                'name' => 'Utilities',
            ])
            ->assertRedirect();

        $category = $account->expenseCategories()->sole();

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.expense-categories.update', [$account, $category]), [
                'name' => 'Rent and utilities',
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.expense-categories.status', [$account, $category]), [
                'is_active' => '0',
            ])
            ->assertRedirect();

        $this->assertSame('Rent and utilities', $category->fresh()->name);
        $this->assertFalse($category->fresh()->is_active);
        $this->assertModelExists($category);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.expense-categories.status', [$account, $category]), [
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_cashdesk_expense_creates_linked_cash_out_and_void_creates_one_idempotent_reversal(): void
    {
        [$owner, $account] = $this->ownerContext([
            'default_currency' => 'UAH',
            'timezone' => 'Europe/Kyiv',
        ]);
        $location = Location::factory()->for($account)->create();
        $category = ExpenseCategory::factory()->for($account)->create(['name' => 'Cleaning']);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.expenses.store', $account), [
                'expense_category_id' => $category->id,
                'amount' => '325.40',
                'occurred_at' => '2026-07-10T12:30',
                'reason' => 'Cleaning products and supplies.',
                'payment_method' => StudioExpense::PaymentMethodCashdesk,
                'location_id' => $location->id,
            ])
            ->assertRedirect();

        $expense = $account->studioExpenses()->sole();

        $this->assertSame(32540, $expense->amount_cents);
        $this->assertSame('UAH', $expense->currency);
        $this->assertTrue($expense->occurred_at->equalTo(Carbon::parse('2026-07-10 09:30:00', 'UTC')));
        $this->assertSame($owner->id, $expense->actor_user_id);
        $this->assertSame($owner->name, $expense->actor_name);
        $this->assertSame(StudioExpense::StatusActive, $expense->status());
        $this->assertDatabaseHas('studio_cash_entries', [
            'studio_expense_id' => $expense->id,
            'direction' => StudioCashEntry::DirectionOut,
            'purpose' => StudioCashEntry::PurposeOperationalExpense,
            'amount_cents' => 32540,
            'location_id' => $location->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.expenses.void', [$account, $expense]), [
                'reason' => 'Receipt was entered twice.',
            ])
            ->assertRedirect();

        $expense->refresh();

        $this->assertTrue($expense->isVoided());
        $this->assertSame(StudioExpense::StatusVoided, $expense->status());
        $this->assertSame('Receipt was entered twice.', $expense->void_reason);
        $this->assertSame($owner->id, $expense->voided_by_actor_user_id);
        $this->assertSame(2, $expense->cashEntries()->count());
        $this->assertDatabaseHas('studio_cash_entries', [
            'studio_expense_id' => $expense->id,
            'direction' => StudioCashEntry::DirectionIn,
            'purpose' => StudioCashEntry::PurposeExpenseReversal,
            'amount_cents' => 32540,
        ]);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.expenses.void', [$account, $expense]), [
                'reason' => 'Repeated void request.',
            ])
            ->assertRedirect();

        $this->assertSame(2, $expense->cashEntries()->count());
        $this->assertSame('Receipt was entered twice.', $expense->fresh()->void_reason);
    }

    public function test_non_cash_expenses_do_not_change_cashdesk_and_cashdesk_requires_location(): void
    {
        [$owner, $account] = $this->ownerContext();
        $location = Location::factory()->for($account)->create();
        $category = ExpenseCategory::factory()->for($account)->create();

        foreach ([
            StudioExpense::PaymentMethodBankCard => $location->id,
            StudioExpense::PaymentMethodBankTransfer => null,
            StudioExpense::PaymentMethodOther => null,
        ] as $paymentMethod => $locationId) {
            $this->actingAs($owner)
                ->post(route('dashboard.accounts.expenses.store', $account), array_filter([
                    'expense_category_id' => $category->id,
                    'amount' => '100.00',
                    'occurred_at' => '2026-07-15T10:00',
                    'reason' => 'Operational studio expense.',
                    'payment_method' => $paymentMethod,
                    'location_id' => $locationId,
                ], fn (mixed $value): bool => $value !== null))
                ->assertRedirect();
        }

        $this->assertSame(3, $account->studioExpenses()->count());
        $this->assertSame(0, $account->studioCashEntries()->count());

        $cardExpense = $account->studioExpenses()
            ->where('payment_method', StudioExpense::PaymentMethodBankCard)
            ->sole();

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.expenses.void', [$account, $cardExpense]), [
                'reason' => 'Non-cash expense was duplicated.',
            ])
            ->assertRedirect();

        $this->assertTrue($cardExpense->fresh()->isVoided());
        $this->assertSame(0, $account->studioCashEntries()->count());

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.expenses.store', $account), [
                'expense_category_id' => $category->id,
                'amount' => '100.00',
                'occurred_at' => '2026-07-15T10:00',
                'reason' => 'Missing cashdesk location.',
                'payment_method' => StudioExpense::PaymentMethodCashdesk,
            ])
            ->assertSessionHasErrors('location_id');

        $this->assertSame(3, $account->studioExpenses()->count());
    }

    public function test_expense_management_requires_permission_and_enforces_account_boundaries(): void
    {
        [$owner, $account] = $this->ownerContext();
        $staff = User::factory()->create();
        $account->users()->syncWithoutDetaching([
            $staff->id => ['role' => AccountRole::Trainer->value, 'permissions' => null],
        ]);
        $category = ExpenseCategory::factory()->for($account)->create();
        $otherAccount = Account::factory()->create();
        $otherCategory = ExpenseCategory::factory()->for($otherAccount)->create();
        $otherLocation = Location::factory()->for($otherAccount)->create();
        $otherExpense = StudioExpense::factory()
            ->for($otherAccount)
            ->for($otherCategory, 'category')
            ->create();

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.expense-categories.store', $account), ['name' => 'Forbidden'])
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.expenses.store', $account), [
                'expense_category_id' => $category->id,
                'amount' => '100.00',
                'occurred_at' => '2026-07-15T10:00',
                'reason' => 'Forbidden expense attempt.',
                'payment_method' => StudioExpense::PaymentMethodOther,
            ])
            ->assertForbidden();

        $account->memberships()
            ->whereBelongsTo($staff)
            ->update(['permissions' => [StudioPermission::ManageStudioCashflow->value]]);

        $this->actingAs($staff->fresh())
            ->post(route('dashboard.accounts.expense-categories.store', $account), ['name' => 'Allowed'])
            ->assertRedirect();

        $foreignPayload = [
            'expense_category_id' => $otherCategory->id,
            'amount' => '100.00',
            'occurred_at' => '2026-07-15T10:00',
            'reason' => 'Wrong account relations.',
            'payment_method' => StudioExpense::PaymentMethodCashdesk,
            'location_id' => $otherLocation->id,
        ];

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.expenses.store', $account), $foreignPayload)
            ->assertSessionHasErrors(['expense_category_id', 'location_id']);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.expense-categories.update', [$account, $otherCategory]), [])
            ->assertNotFound();

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.expenses.void', [$account, $otherExpense]), [])
            ->assertNotFound();

        $this->assertSame(0, $account->studioExpenses()->count());
        $this->assertFalse($otherExpense->fresh()->isVoided());
        $this->assertModelExists($category);
    }

    public function test_inactive_categories_cannot_be_used_and_used_categories_remain_auditable(): void
    {
        [$owner, $account] = $this->ownerContext();
        $category = ExpenseCategory::factory()->for($account)->create(['is_active' => false]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.expenses.store', $account), [
                'expense_category_id' => $category->id,
                'amount' => '100.00',
                'occurred_at' => '2026-07-15T10:00',
                'reason' => 'Inactive category must fail.',
                'payment_method' => StudioExpense::PaymentMethodOther,
            ])
            ->assertSessionHasErrors('expense_category_id');

        $category->update(['is_active' => true]);
        $expense = StudioExpense::factory()
            ->for($account)
            ->for($category, 'category')
            ->create();
        $category->update(['is_active' => false]);

        $this->assertModelExists($category);
        $this->assertModelExists($expense);
        $this->assertSame(1, $category->expenses()->count());
        $this->assertSame(1, $account->studioExpenses()->active()->count());
        $this->assertSame(0, $account->studioExpenses()->voided()->count());
    }

    public function test_expense_ledger_relations_do_not_block_account_deletion(): void
    {
        [$owner, $account] = $this->ownerContext();
        $location = Location::factory()->for($account)->create();
        $category = ExpenseCategory::factory()->for($account)->create();
        $expense = StudioExpense::factory()
            ->for($account)
            ->for($category, 'category')
            ->for($location)
            ->create(['payment_method' => StudioExpense::PaymentMethodCashdesk]);
        $cashEntry = StudioCashEntry::factory()
            ->for($account)
            ->for($location)
            ->for($expense, 'expense')
            ->create([
                'direction' => StudioCashEntry::DirectionOut,
                'purpose' => StudioCashEntry::PurposeOperationalExpense,
            ]);

        $account->delete();

        $this->assertModelMissing($category);
        $this->assertModelMissing($expense);
        $this->assertModelMissing($cashEntry);
        $this->assertModelExists($owner);
    }

    /**
     * @param  array<string, mixed>  $accountAttributes
     * @return array{0: User, 1: Account}
     */
    private function ownerContext(array $accountAttributes = []): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create($accountAttributes);
        $account->addOwner($owner);

        return [$owner, $account];
    }
}
