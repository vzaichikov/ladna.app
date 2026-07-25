<?php

namespace App\Models;

use App\Enums\SalaryClassFormulaType;
use Database\Factories\SalaryModelClassRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id', 'salary_model_version_id', 'class_type_id', 'class_type_name', 'is_default',
    'formula_type', 'flat_amount_cents', 'person_rate_cents', 'minimum_people', 'base_amount_cents',
    'included_people', 'hourly_rate_cents', 'extra_person_rate_cents', 'minimum_pay_cents',
    'maximum_pay_cents',
])]
class SalaryModelClassRule extends Model
{
    /** @use HasFactory<SalaryModelClassRuleFactory> */
    use HasFactory;

    protected $attributes = [
        'is_default' => false,
        'minimum_people' => 0,
        'included_people' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'formula_type' => SalaryClassFormulaType::class,
            'flat_amount_cents' => 'integer',
            'person_rate_cents' => 'integer',
            'minimum_people' => 'integer',
            'base_amount_cents' => 'integer',
            'included_people' => 'integer',
            'hourly_rate_cents' => 'integer',
            'extra_person_rate_cents' => 'integer',
            'minimum_pay_cents' => 'integer',
            'maximum_pay_cents' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SalaryModelVersion::class, 'salary_model_version_id');
    }

    public function classType(): BelongsTo
    {
        return $this->belongsTo(ClassType::class);
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(SalaryModelClassRuleTier::class);
    }
}
