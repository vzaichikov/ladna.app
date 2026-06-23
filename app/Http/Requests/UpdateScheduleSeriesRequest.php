<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\Trainer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScheduleSeriesRequest extends FormRequest
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
            'location_id' => ['required', Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id)],
            'room_id' => ['required', Rule::exists((new Room)->getTable(), 'id')->where('account_id', $account?->id)],
            'class_type_id' => ['required', Rule::exists((new ClassType)->getTable(), 'id')
                ->where('account_id', $account?->id)
                ->where('schedule_kind', ScheduleKind::GroupClass->value)],
            'trainer_id' => ['nullable', Rule::exists((new Trainer)->getTable(), 'id')->where('account_id', $account?->id)],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'start_time' => ['required', 'date_format:H:i'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'booking_cutoff_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'status' => ['required', Rule::enum(ScheduleSeriesStatus::class)],
        ];
    }
}
