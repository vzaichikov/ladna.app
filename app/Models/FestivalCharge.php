<?php

namespace App\Models;

use App\Enums\FestivalChargeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_entry_id', 'festival_entry_step_id', 'festival_charge_definition_id', 'festival_entry_requirement_id', 'festival_submission_id', 'pricing_key', 'code', 'kind', 'name', 'status', 'amount_cents', 'currency', 'due_at', 'paid_at', 'cancelled_at', 'refunded_at', 'approved_by', 'notes'])]
class FestivalCharge extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['status' => FestivalChargeStatus::class, 'amount_cents' => 'integer', 'due_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function hasPaymentHistory(): bool
    {
        $hasAttempts = $this->relationLoaded('paymentAttempts')
            ? $this->paymentAttempts->isNotEmpty()
            : $this->paymentAttempts()->exists();

        return $hasAttempts || ($this->amount_cents > 0 && ($this->paid_at !== null || in_array($this->status, [
            FestivalChargeStatus::Paid,
            FestivalChargeStatus::PaidRequiresRefund,
            FestivalChargeStatus::Refunded,
        ], true)));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(FestivalChargeDefinition::class, 'festival_charge_definition_id');
    }

    public function entryStep(): BelongsTo
    {
        return $this->belongsTo(FestivalEntryStep::class, 'festival_entry_step_id');
    }

    public function sourceRequirement(): BelongsTo
    {
        return $this->belongsTo(FestivalEntryRequirement::class, 'festival_entry_requirement_id');
    }

    public function sourceSubmission(): BelongsTo
    {
        return $this->belongsTo(FestivalSubmission::class, 'festival_submission_id');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(FestivalPaymentAttempt::class);
    }
}
