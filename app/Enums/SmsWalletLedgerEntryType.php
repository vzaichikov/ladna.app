<?php

namespace App\Enums;

enum SmsWalletLedgerEntryType: string
{
    case TopUp = 'top_up';
    case Usage = 'usage';
    case ManualAdjustment = 'manual_adjustment';
    case PaymentReversal = 'payment_reversal';
    case Reconciliation = 'reconciliation';
}
