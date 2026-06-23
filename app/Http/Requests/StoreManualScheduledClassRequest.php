<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\Trainer;
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
            'location_id' => ['required', Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id)],
            'room_id' => ['required', Rule::exists((new Room)->getTable(), 'id')->where('account_id', $account?->id)],
            'class_type_id' => ['required', Rule::exists((new ClassType)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('schedule_kind', $scheduleKind?->value ?? '')],
            'trainer_id' => ['nullable', Rule::exists((new Trainer)->getTable(), 'id')->where('account_id', $account?->id)],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'booking_cutoff_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->route('account');
                $scheduleKind = $this->scheduleKind();

                if (! $scheduleKind || ! in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true)) {
                    $validator->errors()->add('class_type_id', __('app.manual_class_format_invalid'));

                    return;
                }

                if (! $account?->hasScheduleKindEnabled($scheduleKind)) {
                    $validator->errors()->add('class_type_id', __('app.manual_class_format_disabled'));
                }

                $roomId = (int) $this->input('room_id');
                $locationId = (int) $this->input('location_id');

                if ($roomId > 0 && $locationId > 0 && ! $account?->rooms()->whereKey($roomId)->where('location_id', $locationId)->exists()) {
                    $validator->errors()->add('room_id', __('app.room_location_mismatch'));
                }

                if ($scheduleKind === ScheduleKind::PrivateLesson && blank($this->input('trainer_id'))) {
                    $validator->errors()->add('trainer_id', __('app.private_lesson_trainer_required'));
                }
            },
        ];
    }

    private function scheduleKind(): ?ScheduleKind
    {
        return ScheduleKind::tryFrom((string) $this->route('scheduleKind'));
    }
}
