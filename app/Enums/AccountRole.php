<?php

namespace App\Enums;

enum AccountRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Trainer = 'trainer';
    case Receptionist = 'receptionist';

    /**
     * @return array<int, StudioPermission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Owner, self::Admin => StudioPermission::cases(),
            self::Manager => [
                StudioPermission::ManageSchedule,
                StudioPermission::ManageClients,
                StudioPermission::ManageBookings,
                StudioPermission::ManageWebsiteLeads,
                StudioPermission::MarkAttendance,
                StudioPermission::ManageTrainers,
            ],
            self::Trainer => [
                StudioPermission::ManageSchedule,
                StudioPermission::ManageBookings,
                StudioPermission::MarkAttendance,
            ],
            self::Receptionist => [
                StudioPermission::ManageClients,
                StudioPermission::ManageBookings,
                StudioPermission::ManageWebsiteLeads,
                StudioPermission::MarkAttendance,
            ],
        };
    }

    public function labelKey(): string
    {
        return 'app.role_'.$this->value;
    }
}
