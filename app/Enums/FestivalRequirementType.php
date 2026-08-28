<?php

namespace App\Enums;

enum FestivalRequirementType: string
{
    case Music = 'music';
    case QualificationVideo = 'qualification_video';
    case Backdrop = 'backdrop';
    case Waiver = 'waiver';
    case Insurance = 'insurance';
    case PaymentProof = 'payment_proof';
    case CustomDocument = 'custom_document';
    case HelperSelection = 'helper_selection';
}
