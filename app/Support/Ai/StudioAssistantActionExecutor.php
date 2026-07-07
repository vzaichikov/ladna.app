<?php

namespace App\Support\Ai;

use App\Actions\CreateQuickBooking;
use App\Actions\ReconcileCustomerClassPassForBooking;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Models\AiPendingAction;
use App\Models\ClassBooking;
use App\Models\User;
use App\Support\ClassBookingCancellationWindow;
use App\Support\CustomerNotifications\ClassBookingNotificationCoordinator;
use App\Support\PhoneNumberNormalizer;
use App\Support\ScheduleKindRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudioAssistantActionExecutor
{
    public function __construct(
        private readonly CreateQuickBooking $createQuickBooking,
        private readonly ReconcileCustomerClassPassForBooking $reconcileCustomerClassPassForBooking,
        private readonly ClassBookingCancellationWindow $cancellationWindow,
        private readonly ClassBookingNotificationCoordinator $notifications,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(AiPendingAction $action, User $user): array
    {
        $action->loadMissing('account');

        if (! $action->account || ! $user->can('manageBookings', $action->account)) {
            throw new AuthorizationException(__('app.assistant_action_forbidden'));
        }

        if (! $action->isPending()) {
            throw ValidationException::withMessages([
                'action' => __('app.assistant_action_not_pending'),
            ]);
        }

        return match ($action->action_name) {
            'create-booking' => $this->createBooking($action, $user),
            'cancel-booking' => $this->cancelBooking($action),
            default => throw ValidationException::withMessages([
                'action' => __('app.assistant_action_unknown'),
            ]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function createBooking(AiPendingAction $action, User $user): array
    {
        $account = $action->account;
        $arguments = $this->validateCreateBookingArguments($action);
        $booking = $this->createQuickBooking->execute($account, $user, $arguments);

        $action->update([
            'status' => AiPendingAction::StatusExecuted,
            'confirmed_at' => now(),
            'executed_at' => now(),
            'result' => [
                'booking_id' => $booking->id,
                'scheduled_class_id' => $booking->scheduled_class_id,
            ],
        ]);

        return [
            'message' => __('app.assistant_booking_created', ['id' => $booking->id]),
            'booking_id' => $booking->id,
            'scheduled_class_id' => $booking->scheduled_class_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelBooking(AiPendingAction $action): array
    {
        $arguments = Validator::make($action->arguments ?? [], [
            'booking_id' => ['required', 'integer'],
        ])->validate();

        $booking = ClassBooking::query()
            ->whereBelongsTo($action->account)
            ->with('scheduledClass')
            ->findOrFail((int) $arguments['booking_id']);

        if ($this->cancellationWindow->isLockedForBooking($booking)) {
            throw ValidationException::withMessages([
                'booking' => __('app.booking_cancellation_cutoff_locked'),
            ]);
        }

        $previousStatus = $booking->status;

        $booking->update([
            'status' => ClassBookingStatus::Cancelled->value,
            'attended_at' => null,
        ]);
        $this->reconcileCustomerClassPassForBooking->execute($booking);

        if ($previousStatus !== ClassBookingStatus::Cancelled) {
            $this->notifications->bookingCancelled($booking);
        }

        $action->update([
            'status' => AiPendingAction::StatusExecuted,
            'confirmed_at' => now(),
            'executed_at' => now(),
            'result' => [
                'booking_id' => $booking->id,
                'status' => ClassBookingStatus::Cancelled->value,
            ],
        ]);

        return [
            'message' => __('app.assistant_booking_cancelled', ['id' => $booking->id]),
            'booking_id' => $booking->id,
            'status' => ClassBookingStatus::Cancelled->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCreateBookingArguments(AiPendingAction $action): array
    {
        $account = $action->account;
        $arguments = $action->arguments ?? [];
        $arguments['customer_phone'] = $this->phoneNumberNormalizer->normalize($arguments['customer_phone'] ?? null, $account->country_code);
        $scheduleKind = ScheduleKind::tryFrom((string) ($arguments['schedule_kind'] ?? ''));
        $isGroup = $scheduleKind === ScheduleKind::GroupClass;
        $isManual = $scheduleKind && in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true);

        return Validator::make($arguments, [
            'schedule_kind' => ['required', Rule::enum(ScheduleKind::class)],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('account_id', $account->id)],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer_phone' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'website_lead_id' => ['nullable', Rule::exists('website_leads', 'id')->where('account_id', $account->id)],
            'scheduled_class_id' => [Rule::requiredIf($isGroup), 'nullable', Rule::exists('scheduled_classes', 'id')->where('account_id', $account->id)],
            'location_id' => [Rule::requiredIf((bool) $isManual), 'nullable', Rule::exists('locations', 'id')->where('account_id', $account->id)],
            'room_id' => [Rule::requiredIf((bool) $isManual), 'nullable', Rule::exists('rooms', 'id')->where('account_id', $account->id)],
            'class_type_id' => [Rule::requiredIf((bool) $isManual), 'nullable', Rule::exists('class_types', 'id')->where('account_id', $account->id)],
            'trainer_id' => ['nullable', Rule::exists('trainers', 'id')->where('account_id', $account->id)],
            'starts_at' => [Rule::requiredIf((bool) $isManual), 'nullable', 'date_format:Y-m-d\TH:i'],
        ])->after(function ($validator) use ($account, $arguments, $scheduleKind): void {
            if (! $scheduleKind) {
                return;
            }

            if (! $account->hasScheduleKindEnabled($scheduleKind)) {
                $validator->errors()->add('schedule_kind', __('app.manual_class_format_disabled'));
            }

            if ($scheduleKind === ScheduleKind::PrivateLesson && blank($arguments['trainer_id'] ?? null)) {
                $validator->errors()->add('trainer_id', __('app.private_lesson_trainer_required'));
            }
        })->validate();
    }
}
