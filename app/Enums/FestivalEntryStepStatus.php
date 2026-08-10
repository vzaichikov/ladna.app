<?php

namespace App\Enums;

enum FestivalEntryStepStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
