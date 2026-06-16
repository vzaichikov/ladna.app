<?php

namespace App\Enums;

enum ScheduleKind: string
{
    case GroupClass = 'group_class';
    case PrivateLesson = 'private_lesson';
    case RoomRental = 'room_rental';
}
