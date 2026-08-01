<?php

namespace Tests\Feature;

use App\Actions\ClosePayrollRun;
use App\Actions\VoidPayrollRun;
use App\Enums\AccountRole;
use App\Enums\PayrollCadence;
use App\Enums\SalaryModelType;
use App\Enums\SalaryPeriodUnit;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\SalaryModel;
use App\Models\SalaryModelVersion;
use App\Models\Trainer;
use App\Models\TrainerSalaryAssignment;
use App\Models\User;
use App\Support\Finance\PayrollPeriodResolver;
use App\Support\Salary\TrainerSalaryCalculator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Mockery\MockInterface;
use Tests\TestCase;

class PayrollRunTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_payroll_periods_resolve_weekly_biweekly_semi_monthly_and_monthly_boundaries(): void
    {
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $resolver = app(PayrollPeriodResolver::class);

        $account->update(['payroll_cadence' => PayrollCadence::Weekly]);
        $weekly = $resolver->containing($account->fresh(), '2026-07-15');
        $this->assertSame('2026-07-13', $weekly['starts_on']->toDateString());
        $this->assertSame('2026-07-19', $weekly['ends_on']->toDateString());

        $account->update([
            'payroll_cadence' => PayrollCadence::Biweekly,
            'payroll_anchor_date' => '2026-07-06',
        ]);
        $biweekly = $resolver->containing($account->fresh(), '2026-07-22');
        $this->assertSame('2026-07-20', $biweekly['starts_on']->toDateString());
        $this->assertSame('2026-08-02', $biweekly['ends_on']->toDateString());

        $biweeklyBeforeAnchor = $resolver->containing($account->fresh(), '2026-07-05');
        $this->assertSame('2026-06-22', $biweeklyBeforeAnchor['starts_on']->toDateString());
        $this->assertSame('2026-07-05', $biweeklyBeforeAnchor['ends_on']->toDateString());

        $account->update(['payroll_cadence' => PayrollCadence::SemiMonthly, 'payroll_anchor_date' => null]);
        $semiMonthlyFirstHalf = $resolver->containing($account->fresh(), '2028-02-15');
        $this->assertSame('2028-02-01', $semiMonthlyFirstHalf['starts_on']->toDateString());
        $this->assertSame('2028-02-15', $semiMonthlyFirstHalf['ends_on']->toDateString());
        $this->assertTrue($resolver->matches($account->fresh(), '2028-02-01', '2028-02-15'));
        $this->assertFalse($resolver->matches($account->fresh(), '2028-02-01', '2028-02-14'));

        $semiMonthlyLeapYearSecondHalf = $resolver->containing($account->fresh(), '2028-02-16');
        $this->assertSame('2028-02-16', $semiMonthlyLeapYearSecondHalf['starts_on']->toDateString());
        $this->assertSame('2028-02-29', $semiMonthlyLeapYearSecondHalf['ends_on']->toDateString());

        $semiMonthlyRegularFebruary = $resolver->containing($account->fresh(), '2027-02-28');
        $this->assertSame('2027-02-16', $semiMonthlyRegularFebruary['starts_on']->toDateString());
        $this->assertSame('2027-02-28', $semiMonthlyRegularFebruary['ends_on']->toDateString());

        $latestCompletedAtSecondHalfStart = $resolver->latestCompleted(
            $account->fresh(),
            Carbon::parse('2028-02-16 12:00:00', 'UTC'),
        );
        $this->assertSame('2028-02-01', $latestCompletedAtSecondHalfStart['starts_on']->toDateString());
        $this->assertSame('2028-02-15', $latestCompletedAtSecondHalfStart['ends_on']->toDateString());

        Carbon::setTestNow('2028-03-01 12:00:00');
        $latestCompletedSemiMonthly = $resolver->latestCompleted($account->fresh());
        $this->assertSame('2028-02-16', $latestCompletedSemiMonthly['starts_on']->toDateString());
        $this->assertSame('2028-02-29', $latestCompletedSemiMonthly['ends_on']->toDateString());

        $account->update(['timezone' => 'America/New_York']);
        $timezoneBoundary = $resolver->containing(
            $account->fresh(),
            Carbon::parse('2028-03-01 00:30:00', 'UTC'),
        );
        $this->assertSame('2028-02-16', $timezoneBoundary['starts_on']->toDateString());
        $this->assertSame('2028-02-29', $timezoneBoundary['ends_on']->toDateString());

        $account->update(['timezone' => 'UTC', 'payroll_cadence' => PayrollCadence::Monthly, 'payroll_anchor_date' => null]);
        $monthly = $resolver->containing($account->fresh(), '2028-02-12');
        $this->assertSame('2028-02-01', $monthly['starts_on']->toDateString());
        $this->assertSame('2028-02-29', $monthly['ends_on']->toDateString());

        Carbon::setTestNow(Carbon::parse('2026-08-03 00:30:00', 'UTC'));
        $account->update(['timezone' => 'America/New_York', 'payroll_cadence' => PayrollCadence::Weekly]);
        $latestCompleted = $resolver->latestCompleted($account->fresh());
        $this->assertSame('2026-07-20', $latestCompleted['starts_on']->toDateString());
        $this->assertSame('2026-07-26', $latestCompleted['ends_on']->toDateString());
    }

    public function test_payroll_cadence_update_validates_anchor_and_clears_irrelevant_anchor(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'payroll_cadence' => PayrollCadence::Monthly,
            'payroll_anchor_date' => null,
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.cadence.update', $account), [
                'cadence' => PayrollCadence::Biweekly->value,
            ])
            ->assertSessionHasErrors('payroll_anchor_date');
        $this->assertSame(PayrollCadence::Monthly, $account->fresh()->payroll_cadence);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.cadence.update', $account), [
                'cadence' => 'fortnightly',
                'payroll_anchor_date' => '2026-07-06',
            ])
            ->assertSessionHasErrors('cadence');

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.cadence.update', $account), [
                'cadence' => PayrollCadence::Biweekly->value,
                'payroll_anchor_date' => '06-07-2026',
            ])
            ->assertSessionHasErrors('payroll_anchor_date');

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.cadence.update', $account), [
                'cadence' => PayrollCadence::Biweekly->value,
                'payroll_anchor_date' => '2026-07-06',
            ])
            ->assertRedirect(route('dashboard.accounts.payroll.index', $account));
        $this->assertSame(PayrollCadence::Biweekly, $account->fresh()->payroll_cadence);
        $this->assertSame('2026-07-06', $account->fresh()->payroll_anchor_date?->toDateString());

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.cadence.update', $account), [
                'cadence' => PayrollCadence::Weekly->value,
                'payroll_anchor_date' => '2025-01-01',
            ])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(PayrollCadence::Weekly, $account->fresh()->payroll_cadence);
        $this->assertNull($account->fresh()->payroll_anchor_date);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.cadence.update', $account), [
                'cadence' => PayrollCadence::SemiMonthly->value,
                'payroll_anchor_date' => '2025-01-01',
            ])
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(PayrollCadence::SemiMonthly, $account->fresh()->payroll_cadence);
        $this->assertNull($account->fresh()->payroll_anchor_date);
    }

    public function test_payroll_routes_are_permission_and_tenant_protected(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $otherAccount = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $payrollManager = User::factory()->create();
        $cashManager = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($payrollManager, 'user')
            ->create([
                'role' => AccountRole::Receptionist->value,
                'permissions' => [StudioPermission::ManageStudioPayroll->value],
            ]);
        AccountMembership::factory()
            ->for($account)
            ->for($cashManager, 'user')
            ->create([
                'role' => AccountRole::Receptionist->value,
                'permissions' => [StudioPermission::ManageStudioCashflow->value],
            ]);

        $this->get(route('dashboard.accounts.payroll.index', $account))
            ->assertRedirect(route('login'));
        $this->actingAs($cashManager)
            ->get(route('dashboard.accounts.payroll.index', $account))
            ->assertForbidden();
        $this->actingAs($payrollManager)
            ->get(route('dashboard.accounts.payroll.index', $account))
            ->assertOk();
        $this->actingAs($payrollManager)
            ->get(route('dashboard.accounts.payroll.index', $otherAccount))
            ->assertForbidden();

        $this->actingAs($cashManager)
            ->patch(route('dashboard.accounts.payroll.cadence.update', $account), [
                'cadence' => PayrollCadence::Weekly->value,
            ])
            ->assertForbidden();
        $this->actingAs($payrollManager)
            ->patch(route('dashboard.accounts.payroll.cadence.update', $account), [
                'cadence' => PayrollCadence::Monthly->value,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($cashManager)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), $this->closePayload())
            ->assertForbidden();
        $this->assertSame(0, $account->payrollRuns()->count());

        $this->actingAs($payrollManager)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), $this->closePayload())
            ->assertSessionDoesntHaveErrors();
        $run = $account->payrollRuns()->sole();

        $this->actingAs($payrollManager)
            ->patch(route('dashboard.accounts.payroll.runs.void', [$otherAccount, $run]), [
                'reason' => 'Wrong tenant route.',
            ])
            ->assertNotFound();
        $this->assertTrue($run->fresh()->isClosed());

        $this->actingAs($cashManager)
            ->patch(route('dashboard.accounts.payroll.runs.void', [$account, $run]), [
                'reason' => 'Unauthorized void attempt.',
            ])
            ->assertForbidden();
        $this->actingAs($payrollManager)
            ->patch(route('dashboard.accounts.payroll.runs.void', [$account, $run]), [
                'reason' => 'Authorized payroll correction.',
            ])
            ->assertSessionDoesntHaveErrors();
        $this->assertTrue($run->fresh()->isVoided());
    }

    public function test_closed_run_snapshots_real_multicurrency_salary_lines_and_exact_replay_is_idempotent(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $account->addOwner($owner);
        $uahTrainer = Trainer::factory()->for($account)->create(['name' => 'UAH Trainer']);
        $usdTrainer = Trainer::factory()->for($account)->create(['name' => 'USD Trainer']);
        [$uahModel, $uahVersion] = $this->assignDailySalary(
            $account,
            $uahTrainer,
            'UAH Daily',
            'UAH',
            10000,
        );
        $this->assignDailySalary($account, $usdTrainer, 'USD Daily', 'USD', 5000);
        $idempotencyKey = (string) Str::uuid();
        $payload = $this->closePayload($idempotencyKey);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), $payload)
            ->assertRedirect(route('dashboard.accounts.payroll.index', $account));
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), $payload)
            ->assertRedirect(route('dashboard.accounts.payroll.index', $account));

        $run = $account->payrollRuns()->sole();
        $this->assertSame(PayrollRun::StatusClosed, $run->status);
        $this->assertSame(['UAH' => 300000, 'USD' => 150000], $run->totals);
        $this->assertFalse($run->incomplete);
        $this->assertSame($owner->id, $run->closed_by_user_id);
        $this->assertSame(2, $run->lines()->count());
        $this->assertSame(0, $account->studioCashEntries()->count());

        $lines = $run->lines()->get()->keyBy('trainer_id');
        $uahLine = $lines->get($uahTrainer->id);
        $usdLine = $lines->get($usdTrainer->id);
        $this->assertInstanceOf(PayrollRunLine::class, $uahLine);
        $this->assertInstanceOf(PayrollRunLine::class, $usdLine);
        $this->assertSame(['UAH' => 300000], $uahLine->amounts);
        $this->assertSame(['UAH Daily'], $uahLine->model_names);
        $this->assertCount(30, $uahLine->entries);
        $this->assertSame('fixed', $uahLine->entries[0]['kind']);
        $this->assertSame(10000, $uahLine->entries[0]['amount_cents']);
        $this->assertSame('UAH', $uahLine->entries[0]['currency']);
        $this->assertSame(['USD' => 150000], $usdLine->amounts);
        $this->assertSame(['USD Daily'], $usdLine->model_names);

        $originalTotals = $run->totals;
        $originalUahAmounts = $uahLine->amounts;
        $originalUahEntries = $uahLine->entries;
        $uahModel->update(['name' => 'Changed after close']);
        $uahVersion->update(['currency' => 'EUR', 'amount_cents' => 99999]);
        $uahTrainer->update(['name' => 'Renamed trainer']);

        $this->assertSame($originalTotals, $run->fresh()->totals);
        $this->assertSame($originalUahAmounts, $uahLine->fresh()->amounts);
        $this->assertSame($originalUahEntries, $uahLine->fresh()->entries);
        $this->assertSame(['UAH Daily'], $uahLine->fresh()->model_names);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), $payload)
            ->assertSessionDoesntHaveErrors();
        $this->assertSame(1, $account->payrollRuns()->count());
        $this->assertSame(2, $run->lines()->count());
    }

    public function test_incomplete_salary_calculation_rejects_close_without_partial_run_or_lines(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $account->addOwner($owner);
        Trainer::factory()->for($account)->create(['is_active' => true]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), $this->closePayload())
            ->assertSessionHasErrors('period_starts_on');

        $this->assertSame(0, $account->payrollRuns()->count());
        $this->assertSame(0, PayrollRunLine::query()->whereBelongsTo($account)->count());
    }

    public function test_non_matching_reversed_and_unfinished_periods_are_rejected_without_writes(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                'period_starts_on' => '2026-06-01',
                'period_ends_on' => '2026-06-29',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('period_starts_on');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                'period_starts_on' => '2026-06-30',
                'period_ends_on' => '2026-06-01',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('period_ends_on');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                'period_starts_on' => '2026-07-01',
                'period_ends_on' => '2026-07-31',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('period_ends_on');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                'period_starts_on' => '2026-08-01',
                'period_ends_on' => '2026-08-31',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('period_ends_on');

        $this->assertSame(0, $account->payrollRuns()->count());
    }

    public function test_close_defaults_to_latest_completed_period_when_dates_are_omitted(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [])
            ->assertSessionDoesntHaveErrors();

        $run = $account->payrollRuns()->sole();
        $this->assertSame('2026-06-01', $run->period_starts_on->toDateString());
        $this->assertSame('2026-06-30', $run->period_ends_on->toDateString());
        $this->assertTrue(Str::isUuid($run->idempotency_key));
    }

    public function test_semi_monthly_close_uses_the_last_completed_half_month_and_rejects_custom_boundaries(): void
    {
        Carbon::setTestNow('2028-03-01 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::SemiMonthly,
        ]);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                'period_starts_on' => '2028-02-16',
                'period_ends_on' => '2028-02-28',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('period_starts_on');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [])
            ->assertSessionDoesntHaveErrors();

        $run = $account->payrollRuns()->sole();
        $this->assertSame(PayrollCadence::SemiMonthly, $run->cadence);
        $this->assertSame('2028-02-16', $run->period_starts_on->toDateString());
        $this->assertSame('2028-02-29', $run->period_ends_on->toDateString());
    }

    public function test_idempotency_key_rejects_changed_period_tenant_and_replacement_payload(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $otherAccount = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $account->addOwner($owner);
        $otherAccount->addOwner($owner);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), $this->closePayload($idempotencyKey))
            ->assertSessionDoesntHaveErrors();
        $run = $account->payrollRuns()->sole();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSessionHasErrors('idempotency_key');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $otherAccount), $this->closePayload($idempotencyKey))
            ->assertSessionHasErrors('idempotency_key');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                ...$this->closePayload($idempotencyKey),
                'supersedes_payroll_run_id' => $run->id,
            ])
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, PayrollRun::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_void_is_idempotent_only_for_same_trimmed_reason_and_preserves_first_audit(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $account->addOwner($owner);
        app(ClosePayrollRun::class)->execute(
            $account,
            $owner,
            Carbon::parse('2026-06-01', 'UTC'),
            Carbon::parse('2026-06-30', 'UTC'),
            (string) Str::uuid(),
        );
        $run = $account->payrollRuns()->sole();

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.runs.void', [$account, $run]), [
                'reason' => '  Incorrect salary assignment.  ',
            ])
            ->assertSessionDoesntHaveErrors();
        $firstVoid = $run->fresh();
        $this->assertTrue($firstVoid->isVoided());
        $this->assertSame('Incorrect salary assignment.', $firstVoid->void_reason);
        $this->assertSame($owner->id, $firstVoid->voided_by_user_id);

        Carbon::setTestNow('2026-07-31 13:00:00');
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.runs.void', [$account, $run]), [
                'reason' => ' Incorrect salary assignment. ',
            ])
            ->assertSessionDoesntHaveErrors();
        $sameVoid = $run->fresh();
        $this->assertTrue($sameVoid->voided_at?->equalTo($firstVoid->voided_at));
        $this->assertTrue($sameVoid->updated_at->equalTo($firstVoid->updated_at));

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.runs.void', [$account, $run]), [
                'reason' => 'A different reason.',
            ])
            ->assertSessionHasErrors('reason');
        $this->assertSame('Incorrect salary assignment.', $run->fresh()->void_reason);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.runs.void', [$account, $run]), [
                'reason' => 'x',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_payroll_runs_and_lines_are_immutable_and_cannot_be_deleted(): void
    {
        $account = Account::factory()->create();
        $trainer = Trainer::factory()->for($account)->create();
        $run = PayrollRun::factory()->for($account)->create();
        $line = PayrollRunLine::factory()
            ->for($account)
            ->for($run, 'payrollRun')
            ->for($trainer)
            ->create();

        $this->assertLogicException(
            fn () => $run->fresh()->update(['period_ends_on' => '2026-06-29']),
            'Closed payroll runs are immutable.',
        );
        $this->assertLogicException(
            fn () => $run->fresh()->delete(),
            'Payroll runs cannot be deleted.',
        );
        $this->assertLogicException(
            fn () => $line->fresh()->update(['amounts' => ['UAH' => 1]]),
            'Payroll run lines are immutable.',
        );
        $this->assertLogicException(
            fn () => $line->fresh()->delete(),
            'Payroll run lines cannot be deleted.',
        );

        $this->assertModelExists($run);
        $this->assertModelExists($line);
    }

    public function test_close_rolls_back_run_and_all_lines_when_a_later_line_fails(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $actor = User::factory()->create();
        $trainer = Trainer::factory()->for($account)->create();
        $trainerResult = [
            'trainer' => $trainer,
            'amounts' => ['UAH' => 1000],
            'model_names' => ['Test model'],
            'entries' => collect(),
        ];
        $this->mock(TrainerSalaryCalculator::class, function (MockInterface $mock) use ($trainerResult): void {
            $mock->shouldReceive('forAccount')->once()->andReturn([
                'trainers' => collect([$trainerResult, $trainerResult]),
                'totals' => ['UAH' => 2000],
                'incomplete' => false,
                'fixed_ignores_location' => false,
            ]);
        });

        try {
            app(ClosePayrollRun::class)->execute(
                $account,
                $actor,
                Carbon::parse('2026-06-01', 'UTC'),
                Carbon::parse('2026-06-30', 'UTC'),
                (string) Str::uuid(),
            );
            $this->fail('Duplicate payroll line unexpectedly committed.');
        } catch (QueryException) {
            $this->assertSame(0, $account->payrollRuns()->count());
            $this->assertSame(0, PayrollRunLine::query()->whereBelongsTo($account)->count());
        }
    }

    public function test_close_and_void_lock_the_account_for_concurrent_safe_serialization(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $account->addOwner($owner);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $run = app(ClosePayrollRun::class)->execute(
            $account,
            $owner,
            Carbon::parse('2026-06-01', 'UTC'),
            Carbon::parse('2026-06-30', 'UTC'),
            (string) Str::uuid(),
        );
        app(VoidPayrollRun::class)->execute($account, $run, $owner, 'Concurrency audit reason.');

        $accountLocks = collect($queries)->filter(
            fn (string $query): bool => str_contains($query, 'from `accounts`')
                && str_contains($query, 'for update'),
        );
        $runLocks = collect($queries)->filter(
            fn (string $query): bool => str_contains($query, 'from `payroll_runs`')
                && str_contains($query, 'for update'),
        );
        $this->assertGreaterThanOrEqual(2, $accountLocks->count());
        $this->assertGreaterThanOrEqual(1, $runLocks->count());
    }

    public function test_voided_payroll_run_can_be_replaced_but_closed_period_cannot_overlap(): void
    {
        Carbon::setTestNow('2026-07-31 12:00:00');
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $account->addOwner($owner);
        $period = [
            'period_starts_on' => '2026-06-01',
            'period_ends_on' => '2026-06-30',
        ];

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                ...$period,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionDoesntHaveErrors();
        $run = $account->payrollRuns()->sole();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                ...$period,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('period_starts_on');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                ...$period,
                'idempotency_key' => (string) Str::uuid(),
                'supersedes_payroll_run_id' => $run->id,
            ])
            ->assertSessionHasErrors('supersedes_payroll_run_id');

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.payroll.runs.void', [$account, $run]), [
                'reason' => 'A trainer assignment was missing.',
            ])
            ->assertRedirect(route('dashboard.accounts.payroll.index', $account));

        $this->assertTrue($run->fresh()->isVoided());
        $account->update(['payroll_cadence' => PayrollCadence::SemiMonthly]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'idempotency_key' => (string) Str::uuid(),
                'supersedes_payroll_run_id' => $run->id,
            ])
            ->assertSessionHasErrors('supersedes_payroll_run_id');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                ...$period,
                'idempotency_key' => (string) Str::uuid(),
                'supersedes_payroll_run_id' => $run->id,
            ])
            ->assertSessionDoesntHaveErrors();

        $replacement = $account->payrollRuns()->whereKeyNot($run->id)->sole();
        $this->assertTrue($replacement->isClosed());
        $this->assertSame($run->id, $replacement->supersedes_payroll_run_id);
        $this->assertSame(PayrollCadence::Monthly, $replacement->cadence);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                ...$period,
                'idempotency_key' => (string) Str::uuid(),
                'supersedes_payroll_run_id' => $run->id,
            ])
            ->assertSessionHasErrors('supersedes_payroll_run_id');
        $this->assertSame(2, $account->payrollRuns()->count());

        $otherAccount = Account::factory()->create([
            'timezone' => 'UTC',
            'payroll_cadence' => PayrollCadence::Monthly,
        ]);
        $otherAccount->addOwner($owner);
        $foreignRun = PayrollRun::factory()->for($otherAccount)->create([
            'period_starts_on' => '2026-05-01',
            'period_ends_on' => '2026-05-31',
        ]);
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payroll.runs.store', $account), [
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'idempotency_key' => (string) Str::uuid(),
                'supersedes_payroll_run_id' => $foreignRun->id,
            ])
            ->assertSessionHasErrors('supersedes_payroll_run_id');
    }

    /**
     * @return array{0: SalaryModel, 1: SalaryModelVersion}
     */
    private function assignDailySalary(
        Account $account,
        Trainer $trainer,
        string $modelName,
        string $currency,
        int $amountCents,
    ): array {
        $model = SalaryModel::factory()->for($account)->create([
            'name' => $modelName,
            'type' => SalaryModelType::FixedPeriod->value,
        ]);
        $version = SalaryModelVersion::factory()
            ->for($account)
            ->for($model, 'salaryModel')
            ->create([
                'effective_from' => '2026-06-01',
                'currency' => $currency,
                'period_unit' => SalaryPeriodUnit::Day->value,
                'amount_cents' => $amountCents,
            ]);
        TrainerSalaryAssignment::factory()
            ->for($account)
            ->for($trainer)
            ->for($model, 'salaryModel')
            ->create(['effective_from' => '2026-06-01']);

        return [$model, $version];
    }

    /**
     * @return array{period_starts_on: string, period_ends_on: string, idempotency_key: string}
     */
    private function closePayload(?string $idempotencyKey = null): array
    {
        return [
            'period_starts_on' => '2026-06-01',
            'period_ends_on' => '2026-06-30',
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
        ];
    }

    private function assertLogicException(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
