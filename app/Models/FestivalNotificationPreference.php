<?php

namespace App\Models;

use App\Enums\FestivalNotificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_portal_user_id', 'type', 'is_enabled'])]
class FestivalNotificationPreference extends Model
{
    protected $attributes = ['is_enabled' => false];

    protected function casts(): array
    {
        return ['type' => FestivalNotificationType::class, 'is_enabled' => 'boolean'];
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'festival_portal_user_id');
    }
}
