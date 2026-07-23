<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\Trainer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInternalScheduledClassRequest extends FormRequest
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

        return [
            'location_id' => ['required', Rule::exists((new Location)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('is_active', true)],
            'room_id' => ['required', Rule::exists((new Room)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('is_active', true)],
            'class_type_id' => ['required', Rule::exists((new ClassType)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('schedule_kind', ScheduleKind::InternalClass->value)
                ->where('is_active', true)],
            'trainer_id' => ['required', Rule::exists((new Trainer)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('is_active', true)],
            'additional_trainer_ids' => ['sometimes', 'array', 'max:100'],
            'additional_trainer_ids.*' => ['integer', 'distinct:strict', Rule::exists((new Trainer)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('is_active', true)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->route('account');
                $roomId = (int) $this->input('room_id');
                $locationId = (int) $this->input('location_id');

                if ($roomId > 0
                    && $locationId > 0
                    && ! $account?->rooms()->active()->whereKey($roomId)->where('location_id', $locationId)->exists()) {
                    $validator->errors()->add('room_id', __('app.room_location_mismatch'));
                }

                if (collect($this->input('additional_trainer_ids', []))
                    ->contains(fn (mixed $trainerId): bool => (int) $trainerId === (int) $this->input('trainer_id'))) {
                    $validator->errors()->add('additional_trainer_ids', __('app.additional_trainer_cannot_be_main'));
                }
            },
        ];
    }
}
