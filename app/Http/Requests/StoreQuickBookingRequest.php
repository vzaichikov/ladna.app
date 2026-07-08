<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\WebsiteLead;
use App\Support\PhoneNumberNormalizer;
use App\Support\ScheduleKindRegistry;
use App\Support\TrainerActivityDirectionEligibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
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
            'activity_direction_id' => [
                'nullable',
                Rule::exists((new ActivityDirection)->getTable(), 'id')
                    ->where('account_id', $account?->id)
                    ->where('is_active', true),
            ],
            'trainer_id' => ['nullable', Rule::exists((new Trainer)->getTable(), 'id')->where('account_id', $account?->id)],
            'starts_at' => [Rule::requiredIf((bool) $isManual), 'nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => [Rule::requiredIf($scheduleKind === ScheduleKind::RoomRental && $this->input('rental_mode') === 'anytime'), 'nullable', 'date_format:Y-m-d\TH:i'],
            'rental_mode' => ['nullable', Rule::in(['preset', 'anytime'])],
            'payment_amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'ignore_trainer_timeframes' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_name.required_without' => __('app.quick_booking_customer_required'),
            'customer_phone.required_without' => __('app.quick_booking_customer_required'),
            'starts_at.required' => __('app.quick_booking_start_time_required'),
            'ends_at.required' => __('app.quick_booking_end_time_required'),
            'payment_amount.regex' => __('app.quick_booking_payment_amount_invalid'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_name' => __('app.person_name'),
            'customer_phone' => __('app.phone'),
            'scheduled_class_id' => __('app.booking_section'),
            'starts_at' => __('app.start_time'),
            'ends_at' => __('app.end_time'),
            'payment_amount' => __('app.class_booking_payment_amount'),
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

                if ($scheduleKind === ScheduleKind::PrivateLesson) {
                    $trainerActivityDirectionEligibility = app(TrainerActivityDirectionEligibility::class);
                    $activityDirectionId = $trainerActivityDirectionEligibility->activeDirectionId($account, $this->input('activity_direction_id'));

                    if ($trainerActivityDirectionEligibility->accountHasActiveDirections($account) && ! $activityDirectionId) {
                        $validator->errors()->add('activity_direction_id', __('app.private_lesson_activity_direction_required'));
                    }

                    if ($classTypeId > 0 && filled($this->input('trainer_id'))) {
                        $classType = $account->classTypes()
                            ->whereKey($classTypeId)
                            ->where('schedule_kind', $scheduleKind->value)
                            ->first();
                        $trainer = $account->trainers()
                            ->whereKey((int) $this->input('trainer_id'))
                            ->first();

                        if ($classType && $trainer && ! $trainerActivityDirectionEligibility->trainerCanHandle($account, $trainer, $classType, $activityDirectionId)) {
                            $validator->errors()->add('trainer_id', __('app.trainer_activity_direction_mismatch'));
                        }
                    }
                }

                if ($this->input('rental_mode') === 'anytime' && $scheduleKind !== ScheduleKind::RoomRental) {
                    $validator->errors()->add('rental_mode', __('app.rental_mode_anytime_only'));
                }

                if ($scheduleKind === ScheduleKind::RoomRental && $this->input('rental_mode') === 'anytime' && filled($this->input('starts_at')) && filled($this->input('ends_at'))) {
                    if ((string) $this->input('ends_at') <= (string) $this->input('starts_at')) {
                        $validator->errors()->add('ends_at', __('app.anytime_rental_end_after_start'));
                    }
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
            'activity_direction_id' => blank($this->input('activity_direction_id')) ? null : $this->input('activity_direction_id'),
            'website_lead_id' => blank($this->input('website_lead_id')) ? null : $this->input('website_lead_id'),
            'rental_mode' => $this->input('rental_mode') ?: 'preset',
            'ends_at' => blank($this->input('ends_at')) ? null : $this->input('ends_at'),
            'payment_amount' => blank($this->input('payment_amount')) ? null : $this->input('payment_amount'),
            'ignore_trainer_timeframes' => filter_var($this->input('ignore_trainer_timeframes', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJsonValidationResponse()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first() ?: __('app.async_validation_failed'),
                'errors' => $validator->errors(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    private function expectsJsonValidationResponse(): bool
    {
        return $this->expectsJson()
            || $this->ajax()
            || str_contains((string) $this->header('Accept'), 'json');
    }
}
