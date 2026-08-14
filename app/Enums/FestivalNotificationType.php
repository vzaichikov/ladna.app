<?php

namespace App\Enums;

enum FestivalNotificationType: string
{
    case EntrySubmitted = 'entry_submitted';
    case EntryReviewed = 'entry_reviewed';
    case EntryStepReviewed = 'entry_step_reviewed';
    case RequirementDue = 'requirement_due';
    case RequirementReviewed = 'requirement_reviewed';
    case PaymentDue = 'payment_due';
    case PaymentPaid = 'payment_paid';
    case SchedulePublished = 'schedule_published';
    case ScheduleChanged = 'schedule_changed';
    case ResultsPublished = 'results_published';
    case TicketsIssued = 'tickets_issued';
    case Announcement = 'announcement';

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
            self::RequirementReviewed => 'registration',
            self::PaymentDue,
            self::PaymentPaid => 'payments',
            self::SchedulePublished,
            self::ScheduleChanged,
            self::ResultsPublished => 'program',
            self::TicketsIssued => 'tickets',
            self::Announcement => 'announcements',
        };
    }
}
