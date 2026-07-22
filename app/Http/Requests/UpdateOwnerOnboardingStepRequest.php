<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\AccountOnboarding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateOwnerOnboardingStepRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isPlatformAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return match ($this->step()) {
            1 => [
                'studio_stage' => ['required', Rule::in(['operating', 'preparing'])],
                'studio_name' => ['required', 'string', 'max:255'],
                'location_count' => ['required', 'integer', 'min:1', 'max:20'],
                'logo' => ['nullable', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max('2mb')],
            ],
            2 => [
                'location_name' => ['required', 'string', 'max:255'],
                'address' => ['required', 'string', 'max:1000'],
                'room_name' => ['required', 'string', 'max:255'],
                'capacity' => ['required', 'integer', 'min:1', 'max:999'],
            ],
            3 => [
                'teaching_mode' => ['required', Rule::in(['owner', 'another'])],
                'trainer_name' => ['required', 'string', 'max:255'],
            ],
            4 => [
                'direction_name' => ['required', 'string', 'max:255'],
                'class_name' => ['required', 'string', 'max:255'],
                'duration_minutes' => ['required', 'integer', Rule::in([30, 45, 60, 75, 90, 120])],
                'capacity' => ['required', 'integer', 'min:1', 'max:999'],
            ],
            5 => [
                'weekday' => ['required', 'integer', 'min:1', 'max:7'],
                'start_time' => ['required', 'date_format:H:i'],
                'start_date' => ['required', 'date_format:Y-m-d'],
            ],
            default => [],
        };
    }

    public function after(): array
    {
        if ($this->step() !== 5) {
            return [];
        }

        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['weekday', 'start_date'])) {
                    return;
                }

                $startDate = CarbonImmutable::parse($this->string('start_date')->toString(), 'Europe/Kyiv')->startOfDay();
                $today = now('Europe/Kyiv')->startOfDay();

                if ($startDate->isBefore($today)) {
                    $validator->errors()->add('start_date', __('app.onboarding.start_date_not_past'));
                }

                if ($startDate->isoWeekday() !== $this->integer('weekday')) {
                    $validator->errors()->add('start_date', __('app.onboarding.start_date_weekday_mismatch'));
                }

                $account = $this->user()?->accounts()
                    ->whereHas('onboarding', fn ($query) => $query->whereNull('completed_at'))
                    ->first();
                $generationWeeks = $account instanceof Account
                    ? $account->scheduleGenerationWeeks()
                    : Account::defaultScheduleGenerationWeeks();

                if ($startDate->isAfter($today->copy()->addWeeks($generationWeeks)->endOfDay())) {
                    $validator->errors()->add('start_date', __('app.onboarding.start_date_generation_window', [
                        'weeks' => $generationWeeks,
                    ]));
                }
            },
        ];
    }

    private function step(): int
    {
        $step = (int) $this->route('step');

        abort_unless($step >= AccountOnboarding::FirstStep && $step <= AccountOnboarding::LastStep, 404);

        return $step;
    }
}
