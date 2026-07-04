<?php

namespace App\Http\Requests;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Support\ManualQuickBookingAvailability;
use App\Support\PhoneNumberNormalizer;
use App\Support\ScheduleKindRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StorePublicBookingRequest extends FormRequest
{
    private ?Account $accountCache = null;

    private ?Location $locationCache = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->publicAccount();
        $scheduleKind = ScheduleKind::tryFrom((string) $this->input('schedule_kind'));
        $isGroup = $scheduleKind === ScheduleKind::GroupClass;
        $isManual = $scheduleKind && in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true);
        $requiresGuestDetails = ! $this->currentCustomerFor($account) && ($account?->allowsGuestPublicBooking() ?? false);

        return [
            'schedule_kind' => ['required', Rule::enum(ScheduleKind::class)],
            'scheduled_class_id' => [Rule::requiredIf($isGroup), 'nullable', Rule::exists((new ScheduledClass)->getTable(), 'id')->where('account_id', $account?->id)],
            'date' => [Rule::requiredIf((bool) $isManual), 'nullable', 'date_format:Y-m-d'],
            'starts_at' => [Rule::requiredIf((bool) $isManual), 'nullable', 'date_format:Y-m-d\TH:i'],
            'class_type_id' => [Rule::requiredIf((bool) $isManual), 'nullable', Rule::exists((new ClassType)->getTable(), 'id')->where('account_id', $account?->id)],
            'room_id' => [Rule::requiredIf((bool) $isManual), 'nullable', Rule::exists((new Room)->getTable(), 'id')->where('account_id', $account?->id)],
            'trainer_id' => ['nullable', Rule::exists((new Trainer)->getTable(), 'id')->where('account_id', $account?->id)],
            'customer_name' => [Rule::requiredIf($requiresGuestDetails), 'nullable', 'string', 'max:255'],
            'customer_phone' => [Rule::requiredIf($requiresGuestDetails), 'nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->publicAccount();
                $location = $this->publicLocation();
                $scheduleKind = ScheduleKind::tryFrom((string) $this->input('schedule_kind'));

                if (! $account || ! $location || ! $scheduleKind) {
                    return;
                }

                if (! $account->hasScheduleKindEnabled($scheduleKind)) {
                    $validator->errors()->add('schedule_kind', __('app.manual_class_format_disabled'));

                    return;
                }

                if (! $this->currentCustomerFor($account) && $account->allowsGuestPublicBooking()) {
                    $phoneNumberNormalizer = app(PhoneNumberNormalizer::class);

                    if (! $phoneNumberNormalizer->isValid($this->input('customer_phone'), $account->country_code)) {
                        $validator->errors()->add('customer_phone', __('app.public_support_phone_invalid'));
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($scheduleKind === ScheduleKind::GroupClass) {
                    $this->validateGroupSelection($validator, $account, $location);

                    return;
                }

                $this->validateManualSelection($validator, $account, $location, $scheduleKind);
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $account = $this->publicAccount();
        $countryCode = $account?->country_code ?? 'UA';

        $this->merge([
            'customer_phone' => app(PhoneNumberNormalizer::class)->normalize($this->input('customer_phone'), $countryCode),
            'trainer_id' => blank($this->input('trainer_id')) ? null : $this->input('trainer_id'),
            'scheduled_class_id' => blank($this->input('scheduled_class_id')) ? null : $this->input('scheduled_class_id'),
            'class_type_id' => blank($this->input('class_type_id')) ? null : $this->input('class_type_id'),
            'room_id' => blank($this->input('room_id')) ? null : $this->input('room_id'),
        ]);
    }

    private function validateGroupSelection(Validator $validator, Account $account, Location $location): void
    {
        $scheduledClassId = (int) $this->input('scheduled_class_id');

        if ($scheduledClassId <= 0) {
            return;
        }

        $scheduledClass = $account->scheduledClasses()
            ->with('classType')
            ->whereKey($scheduledClassId)
            ->where('location_id', $location->id)
            ->first();

        if (
            ! $scheduledClass
            || ! $scheduledClass->is_public
            || $scheduledClass->status !== ScheduledClassStatus::Scheduled
            || $scheduledClass->starts_at->lessThan(now())
            || $scheduledClass->classType?->schedule_kind !== ScheduleKind::GroupClass
        ) {
            $validator->errors()->add('scheduled_class_id', __('app.quick_booking_group_class_invalid'));

            return;
        }

        if (! $scheduledClass->isBookingOpen()) {
            $validator->errors()->add('scheduled_class_id', __('app.booking_cutoff_closed'));

            return;
        }

        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];
        $customer = $this->currentCustomerFor($account);
        $hasExistingActiveBooking = $customer && $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->where('customer_id', $customer->id)
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($hasExistingActiveBooking) {
            return;
        }

        $capacity = (int) ($scheduledClass->capacity ?? 0);
        $activeBookingsCount = $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->whereIn('status', $activeStatuses)
            ->count();

        if ($capacity <= 0 || $activeBookingsCount >= $capacity) {
            $validator->errors()->add('scheduled_class_id', __('app.no_available_group_slots'));
        }
    }

    private function validateManualSelection(Validator $validator, Account $account, Location $location, ScheduleKind $scheduleKind): void
    {
        if (! in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true)) {
            $validator->errors()->add('schedule_kind', __('app.manual_class_format_invalid'));

            return;
        }

        $classTypeId = (int) $this->input('class_type_id');
        $roomId = (int) $this->input('room_id');
        $trainerId = filled($this->input('trainer_id')) ? (int) $this->input('trainer_id') : null;
        $startsAt = (string) $this->input('starts_at');

        $classTypeExists = $account->classTypes()
            ->active()
            ->whereKey($classTypeId)
            ->where('schedule_kind', $scheduleKind->value)
            ->exists();
        $roomExists = $account->rooms()
            ->active()
            ->whereKey($roomId)
            ->where('location_id', $location->id)
            ->exists();

        if (! $classTypeExists) {
            $validator->errors()->add('class_type_id', __('app.manual_class_format_invalid'));
        }

        if (! $roomExists) {
            $validator->errors()->add('room_id', __('app.room_location_mismatch'));
        }

        if ($scheduleKind === ScheduleKind::PrivateLesson) {
            if (! $trainerId) {
                $validator->errors()->add('trainer_id', __('app.private_lesson_trainer_required'));
            } elseif (! $account->trainers()->active()->whereKey($trainerId)->exists()) {
                $validator->errors()->add('trainer_id', __('app.private_lesson_trainer_required'));
            }
        }

        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if (! app(ManualQuickBookingAvailability::class)->hasStart($account, $scheduleKind, $startsAt, [
            'location_id' => $location->id,
            'room_id' => $roomId,
            'class_type_id' => $classTypeId,
            'trainer_id' => $trainerId,
        ])) {
            $validator->errors()->add('starts_at', __('app.manual_slot_unavailable'));
        }
    }

    private function publicAccount(): ?Account
    {
        if ($this->accountCache) {
            return $this->accountCache;
        }

        $accountSlug = (string) $this->route('accountSlug');
        $this->accountCache = Account::active()->where('slug', $accountSlug)->first();

        return $this->accountCache;
    }

    private function publicLocation(): ?Location
    {
        if ($this->locationCache) {
            return $this->locationCache;
        }

        $account = $this->publicAccount();

        if (! $account) {
            return null;
        }

        $locationSlug = (string) $this->route('locationSlug');
        $this->locationCache = $account->locations()
            ->active()
            ->where('slug', $locationSlug)
            ->first();

        return $this->locationCache;
    }

    private function currentCustomerFor(?Account $account): ?Customer
    {
        if (! $account) {
            return null;
        }

        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->account_id === $account->id ? $customer : null;
    }
}
