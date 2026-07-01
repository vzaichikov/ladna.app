<?php

namespace App\Http\Requests;

use App\Enums\ClassBookingStatus;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrainerReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewReports', $this->route('account')) ?? false;
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
                Rule::exists('locations', 'id')->where(fn ($query) => $query->where('account_id', $account instanceof Account ? $account->id : 0)),
            ],
            'booking_statuses' => ['required', 'array', 'min:1'],
            'booking_statuses.*' => ['required', Rule::enum(ClassBookingStatus::class)],
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, location_id: int|null, booking_statuses: array<int, string>}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'date_from' => (string) $validated['date_from'],
            'date_to' => (string) $validated['date_to'],
            'location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            'booking_statuses' => collect($validated['booking_statuses'])
                ->map(fn (mixed $status): string => (string) $status)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $account = $this->route('account');
        $timezone = $account instanceof Account ? ($account->timezone ?? config('app.timezone')) : config('app.timezone');
        $today = CarbonImmutable::now($timezone);
        $bookingStatuses = $this->input('booking_statuses');

        if (is_string($bookingStatuses)) {
            $bookingStatuses = [$bookingStatuses];
        }

        $this->merge([
            'date_from' => $this->input('date_from') ?: $today->startOfMonth()->toDateString(),
            'date_to' => $this->input('date_to') ?: $today->toDateString(),
            'booking_statuses' => is_array($bookingStatuses) && $bookingStatuses !== []
                ? $bookingStatuses
                : [ClassBookingStatus::Booked->value, ClassBookingStatus::Attended->value],
        ]);
    }
}
