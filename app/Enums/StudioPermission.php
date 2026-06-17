<?php

namespace App\Enums;

enum StudioPermission: string
{
    case ManageSchedule = 'manage_schedule';
    case ManageClients = 'manage_clients';
    case ManageBookings = 'manage_bookings';
    case MarkAttendance = 'mark_attendance';
    case ManageTrainers = 'manage_trainers';
    case ManageStudioSettings = 'manage_studio_settings';
}
