<?php

namespace App\Models;

use App\Enums\FestivalNotificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'type', 'is_enabled', 'is_optional', 'send_email', 'send_sms', 'send_telegram', 'notify_owner_telegram'])]
class FestivalNotificationSetting extends Model
{
    protected $attributes = ['is_enabled' => false, 'is_optional' => true, 'send_email' => true, 'send_sms' => false, 'send_telegram' => true, 'notify_owner_telegram' => false];

    protected function casts(): array
    {
        return ['type' => FestivalNotificationType::class, 'is_enabled' => 'boolean', 'is_optional' => 'boolean', 'send_email' => 'boolean', 'send_sms' => 'boolean', 'send_telegram' => 'boolean', 'notify_owner_telegram' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
