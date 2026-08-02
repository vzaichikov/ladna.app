<?php

namespace App\Enums;

enum TelegramCustomerSessionState: string
{
    case AwaitingContact = 'awaiting_contact';
    case AwaitingFullName = 'awaiting_full_name';
    case ConfirmingCustomer = 'confirming_customer';
    case Idle = 'idle';
    case ChoosingBookingType = 'choosing_booking_type';
    case ChoosingLocation = 'choosing_location';
    case ChoosingDate = 'choosing_date';
    case ChoosingClass = 'choosing_class';
    case ConfirmingBooking = 'confirming_booking';
    case ChoosingPrivateLocation = 'choosing_private_location';
    case ChoosingPrivateDirection = 'choosing_private_direction';
    case ChoosingPrivateService = 'choosing_private_service';
    case ChoosingPrivateTrainer = 'choosing_private_trainer';
    case ChoosingPrivateRoom = 'choosing_private_room';
    case ChoosingPrivateDate = 'choosing_private_date';
    case ChoosingPrivateTime = 'choosing_private_time';
    case ConfirmingPrivateBooking = 'confirming_private_booking';
    case ChoosingBooking = 'choosing_booking';
    case ConfirmingCancellation = 'confirming_cancellation';
    case ChoosingLanguage = 'choosing_language';
    case ConfirmingUnlink = 'confirming_unlink';
}
