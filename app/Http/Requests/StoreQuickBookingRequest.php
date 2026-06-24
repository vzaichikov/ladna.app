<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\WebsiteLead;
use App\Support\PhoneNumberNormalizer;
use App\Support\ScheduleKindRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuickBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageBookings', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $scheduleKind = ScheduleKind::tryFrom((string) $this->input('schedule_kind'));
        $isGroup = $scheduleKind === ScheduleKind::GroupClass;
        $isManual = $scheduleKind && in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true);

        return [
            'schedule_kind' => ['required', Rule::enum(ScheduleKind::class)],
            'customer_id' => ['nullable', Rule::exists((new Customer)->getTable(), 'id')->where('account_id', $account?->id)],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer_phone' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'website_lead_id' => ['nullable', Rule::exists((new WebsiteLead)->getTable(), 'id')->where('account_id', $account?->id)],
            'scheduled_class_id' => [Rule::requiredIf($isGroup), 'nullable', Rule::exists((new ScheduledClass)->getTable(), 'id')->where('account_id', $account?->id)],
            'location_id' => [Rule::requiredIf((bool) $isManual), 'nullable', Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id)],
            'room_id' => [Rule::requiredIf((bool) $isManual), 'nullable', Rule::exists((new Room)->getTable(), 'id')->where('account_id', $account?->id)],
            'class_type_id' => [Rule::requiredIf((bool) $isManual), 'nullable', Rule::exists((new ClassType)->getTable(), 'id')->where('account_id', $account?->id)],
            'trainer_id' => ['nullable', Rule::exists((new Trainer)->getTable(), 'id')->where('account_id', $account?->id)],
            'starts_at' => [Rule::requiredIf((bool) $isManual), 'nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->route('account');
                $scheduleKind = ScheduleKind::tryFrom((string) $this->input('schedule_kind'));

                if (! $account || ! $scheduleKind) {
                    return;
                }

                if (! $account->hasScheduleKindEnabled($scheduleKind)) {
                    $validator->errors()->add('schedule_kind', __('app.manual_class_format_disabled'));
                }

                if ($scheduleKind === ScheduleKind::GroupClass) {
                    $scheduledClassId = (int) $this->input('scheduled_class_id');

                    if ($scheduledClassId > 0 && ! $account->scheduledClasses()->whereKey($scheduledClassId)->whereHas('classType', fn ($query) => $query->where('schedule_kind', ScheduleKind::GroupClass->value))->exists()) {
                        $validator->errors()->add('scheduled_class_id', __('app.quick_booking_group_class_invalid'));
                    }

                    return;
                }

                if (! in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true)) {
                    $validator->errors()->add('schedule_kind', __('app.manual_class_format_invalid'));

                    return;
                }

                $classTypeId = (int) $this->input('class_type_id');

                if ($classTypeId > 0 && ! $account->classTypes()->whereKey($classTypeId)->where('schedule_kind', $scheduleKind->value)->exists()) {
                    $validator->errors()->add('class_type_id', __('app.manual_class_format_invalid'));
                }

                $roomId = (int) $this->input('room_id');
                $locationId = (int) $this->input('location_id');

                if ($roomId > 0 && $locationId > 0 && ! $account->rooms()->whereKey($roomId)->where('location_id', $locationId)->exists()) {
                    $validator->errors()->add('room_id', __('app.room_location_mismatch'));
                }

                if ($scheduleKind === ScheduleKind::PrivateLesson && blank($this->input('trainer_id'))) {
                    $validator->errors()->add('trainer_id', __('app.private_lesson_trainer_required'));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $account = $this->route('account');
        $countryCode = $account?->country_code ?? 'UA';

        $this->merge([
            'customer_phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('customer_phone'), $countryCode),
            'customer_id' => blank($this->input('customer_id')) ? null : $this->input('customer_id'),
            'trainer_id' => blank($this->input('trainer_id')) ? null : $this->input('trainer_id'),
            'website_lead_id' => blank($this->input('website_lead_id')) ? null : $this->input('website_lead_id'),
        ]);
    }
}
