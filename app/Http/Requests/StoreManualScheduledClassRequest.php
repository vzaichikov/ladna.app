<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\Trainer;
use App\Support\RoomActivityDirectionEligibility;
use App\Support\ScheduleKindRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualScheduledClassRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageSchedule', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $scheduleKind = $this->scheduleKind();

        return [
            'location_id' => ['required', Rule::exists((new Location)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('is_active', true)],
            'room_id' => ['required', Rule::exists((new Room)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('is_active', true)],
            'class_type_id' => ['required', Rule::exists((new ClassType)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('schedule_kind', $scheduleKind?->value ?? '')
                ->where('is_active', true)],
            'trainer_id' => ['nullable', Rule::exists((new Trainer)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('is_active', true)],
            'confirm_trainer_overlap' => [
                Rule::prohibitedIf(! ($account instanceof Account && $account->allowsManualTrainerOverlap())),
                'sometimes',
                'boolean',
            ],
            'additional_trainer_ids' => [
                Rule::prohibitedIf($scheduleKind !== ScheduleKind::InternalClass),
                'sometimes',
                'array',
                'max:100',
            ],
            'additional_trainer_ids.*' => ['integer', 'distinct:strict', Rule::exists((new Trainer)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('is_active', true)],
            'title' => [Rule::requiredIf($scheduleKind === ScheduleKind::InternalClass), 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => [Rule::requiredIf($scheduleKind === ScheduleKind::InternalClass), 'nullable', 'integer', 'min:15', 'max:480'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'booking_cutoff_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'cancellation_cutoff_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
        ];
    }

    public function after(RoomActivityDirectionEligibility $roomActivityDirectionEligibility): array
    {
        return [
            function (Validator $validator) use ($roomActivityDirectionEligibility): void {
                $account = $this->route('account');
                $scheduleKind = $this->scheduleKind();

                if (! $scheduleKind || ! in_array($scheduleKind, ScheduleKindRegistry::oneOffRecordKinds(), true)) {
                    $validator->errors()->add('class_type_id', __('app.manual_class_format_invalid'));

                    return;
                }

                if (! $account?->hasScheduleKindEnabled($scheduleKind)) {
                    $validator->errors()->add('class_type_id', __('app.manual_class_format_disabled'));
                }

                $roomId = (int) $this->input('room_id');
                $locationId = (int) $this->input('location_id');

                if ($roomId > 0
                    && $locationId > 0
                    && ! $account?->rooms()->active()->whereKey($roomId)->where('location_id', $locationId)->exists()) {
                    $validator->errors()->add('room_id', __('app.room_location_mismatch'));
                }

                $room = $account?->rooms()
                    ->active()
                    ->whereKey($roomId)
                    ->where('location_id', $locationId)
                    ->first();
                $classType = $account?->classTypes()
                    ->active()
                    ->whereKey((int) $this->input('class_type_id'))
                    ->where('schedule_kind', $scheduleKind?->value ?? '')
                    ->first();

                if ($account && $room && $classType && ! $roomActivityDirectionEligibility->roomCanHost($account, $room, $classType)) {
                    $validator->errors()->add('room_id', __('app.room_activity_direction_mismatch'));
                }

                if ($scheduleKind
                    && (bool) (ScheduleKindRegistry::get($scheduleKind)['trainer_required'] ?? false)
                    && blank($this->input('trainer_id'))) {
                    $validator->errors()->add('trainer_id', __('app.trainer_required'));
                }

                if ($scheduleKind === ScheduleKind::InternalClass
                    && collect($this->input('additional_trainer_ids', []))
                        ->contains(fn (mixed $trainerId): bool => (int) $trainerId === (int) $this->input('trainer_id'))) {
                    $validator->errors()->add('additional_trainer_ids', __('app.additional_trainer_cannot_be_main'));
                }
            },
        ];
    }

    private function scheduleKind(): ?ScheduleKind
    {
        return ScheduleKind::tryFrom((string) $this->route('scheduleKind'));
    }
}
