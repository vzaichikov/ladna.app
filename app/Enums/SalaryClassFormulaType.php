<?php

namespace App\Enums;

enum SalaryClassFormulaType: string
{
    case Flat = 'flat';
    case PerPerson = 'per_person';
    case BasePlusExtra = 'base_plus_extra';
    case HourlyPlusExtra = 'hourly_plus_extra';
    case AttendanceTiers = 'attendance_tiers';
    case ClassValuePercentage = 'class_value_percentage';

    public function labelKey(): string
    {
        return 'app.salary_formula_'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'app.salary_formula_'.$this->value.'_description';
    }
}
