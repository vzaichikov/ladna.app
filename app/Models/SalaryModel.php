<?php

namespace App\Models;

use App\Enums\SalaryModelType;
use Database\Factories\SalaryModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'name', 'type', 'archived_at'])]
class SalaryModel extends Model
{
    /** @use HasFactory<SalaryModelFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SalaryModelType::class,
            'archived_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SalaryModelVersion::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TrainerSalaryAssignment::class);
    }
}
