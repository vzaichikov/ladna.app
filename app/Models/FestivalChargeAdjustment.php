<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_entry_id', 'festival_entry_step_id', 'festival_entry_requirement_id', 'festival_submission_id', 'festival_charge_id', 'idempotency_key', 'direction', 'status', 'amount_cents', 'currency'])]
class FestivalChargeAdjustment extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function entryStep(): BelongsTo
    {
        return $this->belongsTo(FestivalEntryStep::class, 'festival_entry_step_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FestivalEntryRequirement::class, 'festival_entry_requirement_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FestivalSubmission::class, 'festival_submission_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(FestivalCharge::class, 'festival_charge_id');
    }
}
