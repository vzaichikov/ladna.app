<?php

namespace App\Support\Ai;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Support\Str;

class StudioAssistantActionPlanner
{
    public function plan(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation, string $text): ?AiPendingAction
    {
        $normalized = Str::of($text)->lower()->squish()->toString();

        if ($arguments = $this->cancelBookingArguments($normalized)) {
            return $this->createPendingAction($account, $user, $trainer, $conversation, 'cancel-booking', $arguments, $this->cancelBookingPreview($account, $arguments));
        }

        if ($arguments = $this->groupBookingArguments($normalized)) {
            return $this->createPendingAction($account, $user, $trainer, $conversation, 'create-booking', $arguments, $this->createBookingPreview($account, $arguments));
        }

        return null;
    }

    /**
     * @return array{booking_id: int}|null
     */
    private function cancelBookingArguments(string $text): ?array
    {
        $hasCancelIntent = str_contains($text, 'cancel')
            || str_contains($text, 'скас')
            || str_contains($text, 'отмен');

        if (! $hasCancelIntent || preg_match('/(?:booking|запис|брон)[^\d#]*(?:#\s*)?(\d+)/u', $text, $matches) !== 1) {
            return null;
        }

        return ['booking_id' => (int) $matches[1]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function groupBookingArguments(string $text): ?array
    {
        $hasBookingIntent = str_contains($text, 'book')
            || str_contains($text, 'запиш')
            || str_contains($text, 'додай запис');

        if (! $hasBookingIntent) {
            return null;
        }

        $customerId = null;
        $scheduledClassId = null;

        if (preg_match('/(?:customer|client|клієнт|клиент)[^\d#]*(?:#\s*)?(\d+)/u', $text, $matches) === 1) {
            $customerId = (int) $matches[1];
        }

        if (preg_match('/(?:class|занят|тренув)[^\d#]*(?:#\s*)?(\d+)/u', $text, $matches) === 1) {
            $scheduledClassId = (int) $matches[1];
        }

        if (! $customerId || ! $scheduledClassId) {
            return null;
        }

        return [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'customer_id' => $customerId,
            'scheduled_class_id' => $scheduledClassId,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $preview
     */
    private function createPendingAction(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation, string $actionName, array $arguments, array $preview): AiPendingAction
    {
        return AiPendingAction::create([
            'account_id' => $account->id,
            'ai_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'trainer_id' => $trainer?->id,
            'action_name' => $actionName,
            'arguments' => $arguments,
            'preview' => $preview,
            'status' => AiPendingAction::StatusPending,
            'expires_at' => now()->addMinutes(20),
        ]);
    }

    /**
     * @param  array{booking_id: int}  $arguments
     * @return array<string, mixed>
     */
    private function cancelBookingPreview(Account $account, array $arguments): array
    {
        $booking = ClassBooking::query()
            ->whereBelongsTo($account)
            ->with(['customer', 'scheduledClass.location', 'scheduledClass.classType'])
            ->find($arguments['booking_id']);

        if (! $booking) {
            return [
                'summary' => __('app.assistant_cancel_booking_preview_missing', ['id' => $arguments['booking_id']]),
                'warnings' => [__('app.assistant_booking_not_found')],
            ];
        }

        return [
            'summary' => __('app.assistant_cancel_booking_preview', [
                'id' => $booking->id,
                'customer' => $booking->customer?->name ?? __('app.not_set'),
                'class' => $booking->scheduledClass?->title ?? __('app.not_set'),
                'time' => $this->classTime($booking->scheduledClass),
                'location' => $booking->scheduledClass?->location?->name ?? __('app.not_set'),
            ]),
            'booking_id' => $booking->id,
            'customer' => $booking->customer?->name,
            'class' => $booking->scheduledClass?->title,
            'time' => $this->classTime($booking->scheduledClass),
            'location' => $booking->scheduledClass?->location?->name,
            'status' => $booking->status instanceof ClassBookingStatus ? $booking->status->value : $booking->status,
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function createBookingPreview(Account $account, array $arguments): array
    {
        $customer = filled($arguments['customer_id'] ?? null)
            ? $account->customers()->whereKey((int) $arguments['customer_id'])->first()
            : null;
        $scheduledClass = filled($arguments['scheduled_class_id'] ?? null)
            ? ScheduledClass::query()
                ->whereBelongsTo($account)
                ->with(['location', 'classType'])
                ->whereKey((int) $arguments['scheduled_class_id'])
                ->first()
            : null;

        $warnings = [];

        if (! $customer) {
            $warnings[] = __('app.assistant_customer_not_found');
        }

        if (! $scheduledClass) {
            $warnings[] = __('app.assistant_class_not_found');
        }

        return [
            'summary' => __('app.assistant_create_booking_preview', [
                'customer' => $customer?->name ?? '#'.($arguments['customer_id'] ?? '?'),
                'class' => $scheduledClass?->title ?? '#'.($arguments['scheduled_class_id'] ?? '?'),
                'time' => $this->classTime($scheduledClass),
                'location' => $scheduledClass?->location?->name ?? __('app.not_set'),
            ]),
            'customer' => $customer?->name,
            'scheduled_class_id' => $scheduledClass?->id,
            'class' => $scheduledClass?->title,
            'time' => $this->classTime($scheduledClass),
            'location' => $scheduledClass?->location?->name,
            'warnings' => $warnings,
        ];
    }

    private function classTime(?ScheduledClass $scheduledClass): string
    {
        if (! $scheduledClass) {
            return __('app.not_set');
        }

        return $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone())->format('Y-m-d H:i');
    }
}
