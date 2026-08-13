<?php

namespace App\Models;

use Database\Factories\FestivalStreamEntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_online_stream_id', 'festival_ticket_id', 'festival_portal_user_id'])]
class FestivalStreamEntitlement extends Model
{
    /** @use HasFactory<FestivalStreamEntitlementFactory> */
    use HasFactory;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(FestivalOnlineStream::class, 'festival_online_stream_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(FestivalTicket::class, 'festival_ticket_id');
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'festival_portal_user_id');
    }

    public function leases(): HasMany
    {
        return $this->hasMany(FestivalStreamIpLease::class);
    }
}
