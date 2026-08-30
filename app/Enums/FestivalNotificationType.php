<?php

namespace App\Enums;

enum FestivalNotificationType: string
{
    case EntrySubmitted = 'entry_submitted';
    case EntryReviewed = 'entry_reviewed';
    case EntryStepReviewed = 'entry_step_reviewed';
    case RequirementDue = 'requirement_due';
    case RequirementAccepted = 'requirement_accepted';
    case RequirementRejected = 'requirement_rejected';
    case RequirementWaived = 'requirement_waived';
    case RequirementReviewed = 'requirement_reviewed';
    case PaymentDue = 'payment_due';
    case PaymentPaid = 'payment_paid';
    case SchedulePublished = 'schedule_published';
    case ScheduleChanged = 'schedule_changed';
    case ResultsPublished = 'results_published';
    case TicketsIssued = 'tickets_issued';
    case EntrancePassesIssued = 'entrance_passes_issued';
    case Announcement = 'announcement';

    /** @return array<int, self> */
    public static function configurableCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->isConfigurable(),
        ));
    }

    public function isConfigurable(): bool
    {
        return $this !== self::RequirementReviewed;
    }

    public function settingsFallback(): ?self
    {
        return match ($this) {
            self::RequirementAccepted,
            self::RequirementRejected,
            self::RequirementWaived => self::RequirementReviewed,
            default => null,
        };
    }

    public function isOptional(): bool
    {
        return in_array($this, [self::RequirementDue, self::ScheduleChanged, self::Announcement], true);
    }

    public function settingsGroup(): string
    {
        return match ($this) {
            self::EntrySubmitted,
            self::EntryReviewed,
            self::EntryStepReviewed,
            self::RequirementDue,
            self::RequirementAccepted,
            self::RequirementRejected,
            self::RequirementWaived,
            self::RequirementReviewed => 'registration',
            self::PaymentDue,
            self::PaymentPaid => 'payments',
            self::SchedulePublished,
            self::ScheduleChanged,
            self::ResultsPublished => 'program',
            self::TicketsIssued,
            self::EntrancePassesIssued => 'tickets',
            self::Announcement => 'announcements',
        };
    }
}
