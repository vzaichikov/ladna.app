<?php

namespace App\Enums;

enum SalaryPeriodUnit: string
{
    case Month = 'month';
    case Week = 'week';
    case Day = 'day';

    public function labelKey(): string
    {
        return 'app.salary_period_'.$this->value;
    }
}
