<?php

namespace App\Models;

use Database\Factories\SalaryModelClassRuleTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'salary_model_class_rule_id', 'minimum_people', 'maximum_people', 'amount_cents'])]
class SalaryModelClassRuleTier extends Model
{
    /** @use HasFactory<SalaryModelClassRuleTierFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'minimum_people' => 'integer',
            'maximum_people' => 'integer',
            'amount_cents' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function classRule(): BelongsTo
    {
        return $this->belongsTo(SalaryModelClassRule::class, 'salary_model_class_rule_id');
    }
}
