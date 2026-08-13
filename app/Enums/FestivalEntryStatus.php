<?php

namespace App\Enums;

enum FestivalEntryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesPending = 'changes_pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
