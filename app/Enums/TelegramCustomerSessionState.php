<?php

namespace App\Enums;

enum TelegramCustomerSessionState: string
{
    case AwaitingContact = 'awaiting_contact';
    case AwaitingFullName = 'awaiting_full_name';
    case ConfirmingCustomer = 'confirming_customer';
    case Idle = 'idle';
    case ChoosingLocation = 'choosing_location';
    case ChoosingDate = 'choosing_date';
    case ChoosingClass = 'choosing_class';
    case ConfirmingBooking = 'confirming_booking';
    case ChoosingBooking = 'choosing_booking';
    case ConfirmingCancellation = 'confirming_cancellation';
    case ChoosingLanguage = 'choosing_language';
    case ConfirmingUnlink = 'confirming_unlink';
}
