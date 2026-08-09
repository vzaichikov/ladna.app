<?php

namespace App\Enums;

enum FestivalEditionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return match ($this) {
            self::Draft => in_array($next, [self::Published, self::Cancelled], true),
            self::Published => in_array($next, [self::InProgress, self::Cancelled], true),
            self::InProgress => in_array($next, [self::Completed, self::Cancelled], true),
            self::Completed, self::Cancelled => $next === self::Archived,
            self::Archived => false,
        };
    }
}
