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
    case RecordCustomerPayments = 'record_customer_payments';
    case ManageStudioCashflow = 'manage_studio_cashflow';
    case ViewStudioFinancialReports = 'view_studio_financial_reports';
    case ManageStudioPayroll = 'manage_studio_payroll';
    case ViewActivityLog = 'view_activity_log';
    case MarkAttendance = 'mark_attendance';
    case ManageTrainers = 'manage_trainers';
    case ManageStudioSettings = 'manage_studio_settings';
    case ManageEvents = 'manage_events';
    case CheckInEventTickets = 'check_in_event_tickets';

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
            self::CorrectClosedClasses,
            self::ManageStudioCashflow,
            self::ViewStudioFinancialReports,
            self::ManageStudioPayroll => 'critical',
            self::ManageStudioSettings, self::ManageTrainers, self::ManageCustomerClassPasses, self::IssueCustomerClassPasses, self::ManageEvents => 'high',
            default => 'standard',
        };
    }

    public function isCritical(): bool
    {
        return $this->sensitivity() === 'critical';
    }
}
