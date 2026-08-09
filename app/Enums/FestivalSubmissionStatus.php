<?php

namespace App\Enums;

enum FestivalSubmissionStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
