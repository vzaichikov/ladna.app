<?php

namespace App\Models;

use Database\Factories\TrainerNotificationSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'trainer_assignment_enabled', 'class_cancellation_enabled'])]
class TrainerNotificationSetting extends Model
{
    /** @use HasFactory<TrainerNotificationSettingFactory> */
    use HasFactory;

    protected $attributes = [
        'trainer_assignment_enabled' => true,
        'class_cancellation_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trainer_assignment_enabled' => 'boolean',
            'class_cancellation_enabled' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
