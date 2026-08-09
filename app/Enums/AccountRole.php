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
            self::Owner => StudioPermission::cases(),
            self::Admin => array_values(array_filter(
                StudioPermission::cases(),
                fn (StudioPermission $permission): bool => ! $permission->isCritical(),
            )),
            self::Manager => [
                StudioPermission::ManageSchedule,
                StudioPermission::ManageClients,
                StudioPermission::ManageBookings,
                StudioPermission::ManageWebsiteLeads,
                StudioPermission::MarkAttendance,
                StudioPermission::ManageTrainers,
                StudioPermission::ManageEvents,
                StudioPermission::CheckInEventTickets,
                StudioPermission::ManageFestivals,
                StudioPermission::ManageFestivalRegistrations,
                StudioPermission::ManageFestivalSchedule,
                StudioPermission::CheckInFestivalTickets,
                StudioPermission::RecordCustomerPayments,
            ],
            self::Trainer => [
                StudioPermission::ManageSchedule,
                StudioPermission::ManageBookings,
                StudioPermission::MarkAttendance,
                StudioPermission::RecordCustomerPayments,
            ],
            self::Receptionist => [
                StudioPermission::ManageClients,
                StudioPermission::ManageBookings,
                StudioPermission::ManageWebsiteLeads,
                StudioPermission::MarkAttendance,
                StudioPermission::CheckInEventTickets,
                StudioPermission::CheckInFestivalTickets,
                StudioPermission::RecordCustomerPayments,
            ],
        };
    }

    public function labelKey(): string
    {
        return 'app.role_'.$this->value;
    }
}
