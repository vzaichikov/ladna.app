<?php

namespace App\Enums;

enum FestivalQualificationStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
}
