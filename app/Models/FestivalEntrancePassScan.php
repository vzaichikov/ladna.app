<?php

namespace App\Models;

use Database\Factories\FestivalEntrancePassScanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'festival_entrance_pass_id', 'actor_user_id', 'action', 'source', 'request_ip', 'reason', 'occurred_at'])]
class FestivalEntrancePassScan extends Model
{
    /** @use HasFactory<FestivalEntrancePassScanFactory> */
    use HasFactory;

    protected $attributes = ['source' => 'qr'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function entrancePass(): BelongsTo
    {
        return $this->belongsTo(FestivalEntrancePass::class, 'festival_entrance_pass_id');
    }
}
