<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'scheduled_class_id', 'cancelled_by_user_id', 'restored_by_user_id', 'previous_scheduled_class_status', 'rules_snapshot', 'cancelled_at', 'restored_at'])]
class ScheduledClassCancellation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules_snapshot' => 'array',
            'cancelled_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scheduledClass(): BelongsTo
    {
        return $this->belongsTo(ScheduledClass::class);
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function restoredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by_user_id');
    }

    public function effects(): HasMany
    {
        return $this->hasMany(ScheduledClassCancellationEffect::class);
    }
}
