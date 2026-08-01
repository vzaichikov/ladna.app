<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FinanceEpoch;
use App\Models\Location;
use App\Support\DateTimePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinancePeriodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('view', $account) ?? false)
            && ($this->user()?->can('viewStudioFinancialReports', $account) ?? false);
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
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')
                    ->where('account_id', $account instanceof Account ? $account->id : 0),
            ],
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, location_id: int|null}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'date_from' => (string) $validated['date_from'],
            'date_to' => (string) $validated['date_to'],
            'location_id' => filled($validated['location_id'] ?? null) ? (int) $validated['location_id'] : null,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function databaseRange(): array
    {
        $filters = $this->filters();
        $account = $this->route('account');
        $timezone = DateTimePresenter::accountTimezone($account instanceof Account ? $account : null);
        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], $timezone)
            ->startOfDay()
            ->timezone((string) config('app.timezone'));
        $endsAt = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], $timezone)
            ->endOfDay()
            ->timezone((string) config('app.timezone'));
        $epoch = $this->financeEpoch();

        if ($epoch?->starts_at && $startsAt->lessThan($epoch->starts_at)) {
            $startsAt = $epoch->starts_at->toImmutable();
        }

        return [$startsAt, $endsAt];
    }

    public function financeEpoch(): ?FinanceEpoch
    {
        $account = $this->route('account');

        return $account instanceof Account ? $account->activeFinanceEpoch() : null;
    }

    protected function prepareForValidation(): void
    {
        $account = $this->route('account');
        $timezone = DateTimePresenter::accountTimezone($account instanceof Account ? $account : null);
        $today = CarbonImmutable::now($timezone);
        $dateFrom = (string) ($this->input('date_from') ?: $today->startOfMonth()->toDateString());

        if (
            $account instanceof Account
            && $this->isDateString($dateFrom)
            && ($epoch = $account->activeFinanceEpoch())?->starts_at
        ) {
            $epochDate = $epoch->starts_at->copy()->timezone($timezone)->toDateString();

            if ($dateFrom < $epochDate) {
                $dateFrom = $epochDate;
            }
        }

        $this->merge([
            'date_from' => $dateFrom,
            'date_to' => $this->input('date_to') ?: $today->toDateString(),
            'location_id' => blank($this->input('location_id')) ? null : $this->input('location_id'),
        ]);
    }

    private function isDateString(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}
