<?php

namespace App\Enums;

enum StudioPermission: string
{
    case ManageSchedule = 'manage_schedule';
    case ManageClients = 'manage_clients';
    case ManageBookings = 'manage_bookings';
    case ManageWebsiteLeads = 'manage_website_leads';
    case InteractWithTelegramBot = 'interact_with_telegram_bot';
    case IssueCustomerClassPasses = 'issue_customer_class_passes';
    case ManageCustomerClassPasses = 'manage_customer_class_passes';
    case CorrectClosedClasses = 'correct_closed_classes';
    case ManageStudioCashflow = 'manage_studio_cashflow';
    case ViewActivityLog = 'view_activity_log';
    case MarkAttendance = 'mark_attendance';
    case ManageTrainers = 'manage_trainers';
    case ManageStudioSettings = 'manage_studio_settings';

    public function labelKey(): string
    {
        return 'app.permission_'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'app.permission_'.$this->value.'_description';
    }

    public function sensitivity(): string
    {
        return match ($this) {
            self::CorrectClosedClasses, self::ManageStudioCashflow => 'critical',
            self::ManageStudioSettings, self::ManageTrainers, self::ManageCustomerClassPasses, self::IssueCustomerClassPasses => 'high',
            default => 'standard',
        };
    }

    public function isCritical(): bool
    {
        return $this->sensitivity() === 'critical';
    }
}
