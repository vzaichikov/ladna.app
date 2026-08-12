<?php

namespace App\Enums;

enum FestivalScheduleSlotType: string
{
    case Rehearsal = 'rehearsal';
    case Performance = 'performance';
    case Custom = 'custom';
    case FreeHeader = 'free_header';
    case CategoryHeader = 'category_header';

    public function isHeader(): bool
    {
        return in_array($this, [self::FreeHeader, self::CategoryHeader], true);
    }

    public function isTimed(): bool
    {
        return ! $this->isHeader();
    }

    public function requiresEntry(): bool
    {
        return in_array($this, [self::Rehearsal, self::Performance], true);
    }
}
