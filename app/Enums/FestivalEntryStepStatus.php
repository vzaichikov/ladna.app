<?php

namespace App\Enums;

enum FestivalEntryStepStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'crm-status-muted',
            self::Submitted => 'crm-status-scheduled',
            self::ChangesRequested => 'crm-status-warning',
            self::Approved => 'crm-status-active',
            self::Rejected => 'crm-status-danger',
        };
    }
}
