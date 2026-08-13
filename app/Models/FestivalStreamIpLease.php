<?php

namespace App\Models;

use Database\Factories\FestivalStreamIpLeaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_stream_entitlement_id', 'ip_hash', 'first_seen_at', 'last_seen_at', 'expires_at'])]
class FestivalStreamIpLease extends Model
{
    /** @use HasFactory<FestivalStreamIpLeaseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(FestivalStreamEntitlement::class, 'festival_stream_entitlement_id');
    }
}
