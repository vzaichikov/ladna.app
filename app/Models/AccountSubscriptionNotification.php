<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_subscription_id', 'notification_type', 'scheduled_for', 'sent_at', 'context'])]
class AccountSubscriptionNotification extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'context' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AccountSubscription::class, 'account_subscription_id');
    }
}
