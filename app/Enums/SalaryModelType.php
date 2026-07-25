<?php

namespace App\Enums;

enum SalaryModelType: string
{
    case FixedPeriod = 'fixed_period';
    case PerClass = 'per_class';

    public function labelKey(): string
    {
        return 'app.salary_model_type_'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'app.salary_model_type_'.$this->value.'_description';
    }
}
