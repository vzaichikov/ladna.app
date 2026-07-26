<?php

namespace App\Models;

use Database\Factories\CustomerNotificationSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'is_enabled', 'class_reminder_enabled', 'class_reminder_hours_before', 'class_cancellation_enabled'])]
class CustomerNotificationSetting extends Model
{
    /** @use HasFactory<CustomerNotificationSettingFactory> */
    use HasFactory;

    protected $attributes = [
        'is_enabled' => false,
        'class_reminder_enabled' => false,
        'class_reminder_hours_before' => 5,
        'class_cancellation_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'class_reminder_enabled' => 'boolean',
            'class_reminder_hours_before' => 'integer',
            'class_cancellation_enabled' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
