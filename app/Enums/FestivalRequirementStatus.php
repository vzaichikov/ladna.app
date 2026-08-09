<?php

namespace App\Enums;

enum FestivalRequirementStatus: string
{
    case Missing = 'missing';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Waived = 'waived';
}
