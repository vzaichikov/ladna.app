<?php

namespace App\Models;

use App\Enums\FestivalChargeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_entry_id', 'festival_charge_definition_id', 'code', 'kind', 'name', 'status', 'amount_cents', 'currency', 'definition_snapshot', 'due_at', 'paid_at', 'cancelled_at', 'refunded_at', 'approved_by', 'notes'])]
class FestivalCharge extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['status' => FestivalChargeStatus::class, 'amount_cents' => 'integer', 'definition_snapshot' => 'array', 'due_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(FestivalChargeDefinition::class, 'festival_charge_definition_id');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(FestivalPaymentAttempt::class);
    }
}
