<?php

namespace App\Enums;

enum StudioPermission: string
{
    case ManageSchedule = 'manage_schedule';
    case ManageClients = 'manage_clients';
    case ManageBookings = 'manage_bookings';
    case ManageWebsiteLeads = 'manage_website_leads';
    case IssueCustomerClassPasses = 'issue_customer_class_passes';
    case ManageCustomerClassPasses = 'manage_customer_class_passes';
    case ViewActivityLog = 'view_activity_log';
    case MarkAttendance = 'mark_attendance';
    case ManageTrainers = 'manage_trainers';
    case ManageStudioSettings = 'manage_studio_settings';
}
