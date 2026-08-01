<?php

namespace App\Enums;

enum PayrollCadence: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    public function labelKey(): string
    {
        return 'app.payroll_cadence_'.$this->value;
    }
}
