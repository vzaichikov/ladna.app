<?php

namespace App\Enums;

enum FestivalWorkflowStepType: string
{
    case Application = 'application';
    case Form = 'form';
    case Payment = 'payment';
    case Summary = 'summary';
}
