<?php

namespace App\Enums;

enum StudioAiDisposition: string
{
    case Answer = 'answer';
    case OutOfScope = 'out_of_scope';
    case StartBooking = 'start_booking';
    case ContinueBooking = 'continue_booking';
    case CancelBooking = 'cancel_booking';
    case CancelDialog = 'cancel_dialog';

    public function isAction(): bool
    {
        return in_array($this, [
            self::StartBooking,
            self::ContinueBooking,
            self::CancelBooking,
            self::CancelDialog,
        ], true);
    }
}
