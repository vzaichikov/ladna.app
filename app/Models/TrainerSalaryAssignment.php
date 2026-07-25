<?php

namespace App\Models;

use Database\Factories\TrainerSalaryAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'trainer_id', 'salary_model_id', 'created_by_user_id', 'effective_from', 'superseded_at'])]
class TrainerSalaryAssignment extends Model
{
    /** @use HasFactory<TrainerSalaryAssignmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'superseded_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function salaryModel(): BelongsTo
    {
        return $this->belongsTo(SalaryModel::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
