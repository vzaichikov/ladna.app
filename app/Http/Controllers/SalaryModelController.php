<?php

namespace App\Http\Controllers;

use App\Actions\SaveSalaryModel;
use App\Enums\ClassBookingStatus;
use App\Enums\SalaryClassFormulaType;
use App\Enums\SalaryModelType;
use App\Enums\SalaryPeriodUnit;
use App\Http\Requests\SaveSalaryModelRequest;
use App\Models\Account;
use App\Models\SalaryModel;
use App\Support\Payments\PaymentAmounts;
use App\Support\Salary\SalaryModelResolver;
use App\Support\ScheduleKindRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalaryModelController extends Controller
{
    public function index(Request $request, Account $account, SalaryModelResolver $resolver): View
    {
        $this->authorizeCashflow($request, $account);
        $today = now($account->timezone ?: config('app.timezone'))->toDateString();
        $models = $account->salaryModels()
            ->with(['versions' => fn ($query) => $query
                ->whereNull('superseded_at')
                ->orderByDesc('effective_from')
                ->orderByDesc('id'), 'versions.classRules'])
            ->orderBy('name')
            ->get();
        $assignments = $account->trainerSalaryAssignments()
            ->whereNull('superseded_at')
            ->whereDate('effective_from', '<=', $today)
            ->with('salaryModel')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
        $trainers = $account->trainers()->with('trainerType')->orderBy('name')->get();
        $currentAssignments = $trainers->mapWithKeys(fn ($trainer): array => [
            $trainer->id => $resolver->assignmentFor($assignments, $trainer->id, $today),
        ]);
        $modelCards = $models->map(function (SalaryModel $model) use ($currentAssignments, $resolver, $today): array {
            $currentVersion = $resolver->versionFor($model->versions, $model->id, $today)
                ?? $model->versions->first();

            return [
                'model' => $model,
                'current_version' => $currentVersion,
                'assigned_trainers' => $currentAssignments
                    ->filter(fn ($assignment): bool => $assignment?->salary_model_id === $model->id)
                    ->count(),
            ];
        });

        return view('reports.salary-models', [
            'account' => $account,
            'modelCards' => $modelCards,
            'activeModels' => $models->whereNull('archived_at')->values(),
            'trainers' => $trainers,
            'currentAssignments' => $currentAssignments,
            'unassignedTrainers' => $trainers
                ->filter(fn ($trainer): bool => $trainer->is_active && ! $currentAssignments->get($trainer->id))
                ->values(),
            'assignmentDefaultDate' => now($account->timezone ?: config('app.timezone'))->startOfMonth()->toDateString(),
        ]);
    }

    public function create(Request $request, Account $account): View
    {
        $this->authorizeCashflow($request, $account);

        return view('reports.salary-model-form', [
            'account' => $account,
            'salaryModel' => new SalaryModel(['type' => SalaryModelType::PerClass]),
            'version' => null,
            ...$this->formData($account, true),
        ]);
    }

    public function store(
        SaveSalaryModelRequest $request,
        Account $account,
        SaveSalaryModel $saveSalaryModel,
    ): RedirectResponse {
        $salaryModel = $saveSalaryModel->execute($account, $request->validated(), null, $request->user());

        return redirect()->route('dashboard.accounts.salary-models.edit', [$account, $salaryModel])
            ->with('status', __('app.salary_model_created'));
    }

    public function edit(Request $request, Account $account, SalaryModel $salaryModel): View
    {
        $this->authorizeCashflow($request, $account);
        $this->ensureBelongsToAccount($account, $salaryModel);
        abort_if($salaryModel->archived_at !== null, 404);
        $version = $salaryModel->versions()
            ->whereNull('superseded_at')
            ->with('classRules.tiers')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return view('reports.salary-model-form', [
            'account' => $account,
            'salaryModel' => $salaryModel,
            'version' => $version,
            ...$this->formData($account),
        ]);
    }

    public function update(
        SaveSalaryModelRequest $request,
        Account $account,
        SalaryModel $salaryModel,
        SaveSalaryModel $saveSalaryModel,
    ): RedirectResponse {
        $this->ensureBelongsToAccount($account, $salaryModel);
        $saveSalaryModel->execute($account, $request->validated(), $salaryModel, $request->user());

        return redirect()->route('dashboard.accounts.salary-models.edit', [$account, $salaryModel])
            ->with('status', __('app.salary_model_version_created'));
    }

    public function archive(
        Request $request,
        Account $account,
        SalaryModel $salaryModel,
        SalaryModelResolver $resolver,
    ): RedirectResponse {
        $this->authorizeCashflow($request, $account);
        $this->ensureBelongsToAccount($account, $salaryModel);
        $today = now($account->timezone ?: config('app.timezone'))->toDateString();
        $currentAssignments = $account->trainers()
            ->with(['salaryAssignments' => fn ($query) => $query
                ->whereNull('superseded_at')
                ->whereDate('effective_from', '<=', $today)
                ->with('salaryModel')])
            ->get()
            ->map(fn ($trainer) => $resolver->assignmentFor($trainer->salaryAssignments, $trainer->id, $today))
            ->filter();

        if ($currentAssignments->contains('salary_model_id', $salaryModel->id)) {
            return back()->withErrors(['salary_model' => __('app.salary_model_assigned_archive_blocked')]);
        }

        $salaryModel->update(['archived_at' => now()]);

        return redirect()->route('dashboard.accounts.salary-models.index', $account)
            ->with('status', __('app.salary_model_archived'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Account $account, bool $creating = false): array
    {
        return [
            'modelTypes' => SalaryModelType::cases(),
            'periodUnits' => SalaryPeriodUnit::cases(),
            'formulaTypes' => SalaryClassFormulaType::cases(),
            'bookingStatuses' => [
                ClassBookingStatus::Attended,
                ClassBookingStatus::Booked,
                ClassBookingStatus::NoShow,
            ],
            'scheduleKindTabs' => collect(ScheduleKindRegistry::all())
                ->filter(fn (array $definition): bool => (bool) $definition['trainer_reportable'])
                ->all(),
            'classTypes' => $account->classTypes()
                ->whereIn('schedule_kind', ScheduleKindRegistry::trainerReportableValues())
                ->orderBy('name')
                ->get(['id', 'name', 'schedule_kind']),
            'effectiveFromDefault' => $creating
                ? now($account->timezone ?: config('app.timezone'))->startOfMonth()->toDateString()
                : now($account->timezone ?: config('app.timezone'))->toDateString(),
            'centsToDecimal' => fn (?int $cents): string => PaymentAmounts::centsToDecimalString($cents ?? 0),
        ];
    }

    private function authorizeCashflow(Request $request, Account $account): void
    {
        abort_unless($request->user()?->can('manageStudioPayroll', $account), 403);
    }

    private function ensureBelongsToAccount(Account $account, SalaryModel $salaryModel): void
    {
        abort_unless($salaryModel->account_id === $account->id, 404);
    }
}
