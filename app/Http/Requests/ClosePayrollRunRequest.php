<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\PayrollRun;
use App\Support\Finance\PayrollPeriodResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClosePayrollRunRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('manageStudioPayroll', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'period_starts_on' => ['required', 'date_format:Y-m-d'],
            'period_ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_starts_on'],
            'idempotency_key' => ['required', 'uuid'],
            'supersedes_payroll_run_id' => [
                'nullable',
                'integer',
                Rule::exists('payroll_runs', 'id')->where(
                    fn ($query) => $query->where('account_id', $account instanceof Account ? $account->id : 0),
                ),
            ],
        ];
    }

    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            (string) $this->validated('period_starts_on'),
            $this->accountTimezone(),
        );
    }

    public function endsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            (string) $this->validated('period_ends_on'),
            $this->accountTimezone(),
        );
    }

    public function supersededRun(): ?PayrollRun
    {
        $id = $this->validated('supersedes_payroll_run_id');

        return $id ? PayrollRun::query()->findOrFail((int) $id) : null;
    }

    protected function prepareForValidation(): void
    {
        $account = $this->route('account');

        if (! $account instanceof Account) {
            return;
        }

        $suggestedPeriod = app(PayrollPeriodResolver::class)->latestCompleted($account);

        $this->merge([
            'period_starts_on' => $this->input('period_starts_on') ?: $suggestedPeriod['starts_on']->toDateString(),
            'period_ends_on' => $this->input('period_ends_on') ?: $suggestedPeriod['ends_on']->toDateString(),
            'idempotency_key' => $this->input('idempotency_key') ?: (string) Str::uuid(),
        ]);
    }

    private function accountTimezone(): string
    {
        $account = $this->route('account');

        return $account instanceof Account
            ? ($account->timezone ?: config('app.timezone'))
            : config('app.timezone');
    }
}
