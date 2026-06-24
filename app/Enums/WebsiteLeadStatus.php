<?php

namespace App\Enums;

enum WebsiteLeadStatus: string
{
    case New = 'new';
    case Rejected = 'rejected';
    case Booked = 'booked';
    case Callback = 'callback';

    public function labelKey(): string
    {
        return 'app.website_lead_status_'.$this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::New => 'crm-status-scheduled',
            self::Rejected => 'crm-status-danger',
            self::Booked => 'crm-status-active',
            self::Callback => 'crm-status-warning',
        };
    }
}
