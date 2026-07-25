<?php

namespace App\Models;

use App\Enums\ClassBookingStatus;
use App\Enums\SalaryPeriodUnit;
use Database\Factories\SalaryModelVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id', 'salary_model_id', 'created_by_user_id', 'effective_from', 'currency',
    'period_unit', 'amount_cents', 'counted_booking_statuses', 'pay_empty_classes', 'superseded_at',
])]
class SalaryModelVersion extends Model
{
    /** @use HasFactory<SalaryModelVersionFactory> */
    use HasFactory;

    protected $attributes = [
        'pay_empty_classes' => false,
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'period_unit' => SalaryPeriodUnit::class,
            'amount_cents' => 'integer',
            'counted_booking_statuses' => 'array',
            'pay_empty_classes' => 'boolean',
            'superseded_at' => 'datetime',
        ];
    }

    public function salaryModel(): BelongsTo
    {
        return $this->belongsTo(SalaryModel::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function classRules(): HasMany
    {
        return $this->hasMany(SalaryModelClassRule::class);
    }

    /**
     * @return array<int, string>
     */
    public function countedBookingStatusValues(): array
    {
        return collect($this->counted_booking_statuses ?? [])
            ->map(fn (mixed $status): ?string => $status instanceof ClassBookingStatus
                ? $status->value
                : ClassBookingStatus::tryFrom((string) $status)?->value)
            ->filter()
            ->values()
            ->all();
    }
}
