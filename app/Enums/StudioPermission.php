<?php

namespace App\Enums;

enum StudioPermission: string
{
    case ManageSchedule = 'manage_schedule';
    case ManageBookings = 'manage_bookings';
    case MarkAttendance = 'mark_attendance';
    case CorrectClosedClasses = 'correct_closed_classes';
    case ManageClients = 'manage_clients';
    case ManageWebsiteLeads = 'manage_website_leads';
    case IssueCustomerClassPasses = 'issue_customer_class_passes';
    case ManageCustomerClassPasses = 'manage_customer_class_passes';
    case RecordCustomerPayments = 'record_customer_payments';
    case ManageStudioCashflow = 'manage_studio_cashflow';
    case ViewStudioFinancialReports = 'view_studio_financial_reports';
    case ManageStudioPayroll = 'manage_studio_payroll';
    case ManageTrainers = 'manage_trainers';
    case ManageStudioSettings = 'manage_studio_settings';
    case ViewActivityLog = 'view_activity_log';
    case ManageEvents = 'manage_events';
    case CheckInEventTickets = 'check_in_event_tickets';
    case ManageFestivals = 'manage_festivals';
    case ManageFestivalRegistrations = 'manage_festival_registrations';
    case ManageFestivalSchedule = 'manage_festival_schedule';
    case ManageFestivalFinance = 'manage_festival_finance';
    case JudgeFestivals = 'judge_festivals';
    case CheckInFestivalTickets = 'check_in_festival_tickets';
    case DoorStaff = 'door_staff';
    case InteractWithTelegramBot = 'interact_with_telegram_bot';

    public function labelKey(): string
    {
        return 'app.permission_'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'app.permission_'.$this->value.'_description';
    }

    public function detailsKey(): string
    {
        return 'app.permission_'.$this->value.'_details';
    }

    public function group(): string
    {
        return match ($this) {
            self::ManageSchedule,
            self::ManageBookings,
            self::MarkAttendance,
            self::CorrectClosedClasses => 'schedule_and_classes',
            self::ManageClients,
            self::ManageWebsiteLeads,
            self::IssueCustomerClassPasses,
            self::ManageCustomerClassPasses => 'customers_and_sales',
            self::RecordCustomerPayments,
            self::ManageStudioCashflow,
            self::ViewStudioFinancialReports,
            self::ManageStudioPayroll => 'payments_and_finance',
            self::ManageTrainers,
            self::ManageStudioSettings,
            self::ViewActivityLog => 'team_and_settings',
            self::ManageEvents,
            self::CheckInEventTickets,
            self::ManageFestivals,
            self::ManageFestivalRegistrations,
            self::ManageFestivalSchedule,
            self::ManageFestivalFinance,
            self::JudgeFestivals,
            self::CheckInFestivalTickets,
            self::DoorStaff,
            self::InteractWithTelegramBot => 'events_and_tools',
        };
    }

    public function groupLabelKey(): string
    {
        return 'app.permission_group_'.$this->group();
    }

    public function groupDescriptionKey(): string
    {
        return $this->groupLabelKey().'_description';
    }

    public function sensitivity(): string
    {
        return match ($this) {
            self::CorrectClosedClasses,
            self::ManageStudioCashflow,
            self::ViewStudioFinancialReports,
            self::ManageStudioPayroll,
            self::ManageFestivalFinance => 'critical',
            self::ManageStudioSettings,
            self::ManageTrainers,
            self::ManageCustomerClassPasses,
            self::IssueCustomerClassPasses,
            self::ManageEvents,
            self::ManageFestivals,
            self::ManageFestivalRegistrations,
            self::ManageFestivalSchedule,
            self::DoorStaff => 'high',
            default => 'standard',
        };
    }

    public function isCritical(): bool
    {
        return $this->sensitivity() === 'critical';
    }
}
