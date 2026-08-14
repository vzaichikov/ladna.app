<?php

namespace App\Enums;

enum FestivalEntryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesPending = 'changes_pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft, self::Withdrawn => 'crm-status-muted',
            self::Submitted, self::ChangesPending => 'crm-status-scheduled',
            self::UnderReview => 'crm-status-warning',
            self::Accepted => 'crm-status-active',
            self::Rejected => 'crm-status-danger',
        };
    }
}
