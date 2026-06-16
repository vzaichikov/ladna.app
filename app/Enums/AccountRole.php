<?php

namespace App\Enums;

enum AccountRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Instructor = 'instructor';
    case Receptionist = 'receptionist';
}
