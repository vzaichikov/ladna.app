<?php

namespace App\Enums;

enum FestivalCategoryWorkflow: string
{
    case Direct = 'direct';
    case Review = 'review';
    case Qualification = 'qualification';
}
