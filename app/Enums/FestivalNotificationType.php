<?php

namespace App\Enums;

enum FestivalNotificationType: string
{
    case MagicLink = 'magic_link';
    case EntrySubmitted = 'entry_submitted';
    case EntryReviewed = 'entry_reviewed';
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
}
