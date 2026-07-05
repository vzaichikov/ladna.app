<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'scheduled_class_id', 'location_id', 'room_id', 'trainer_id', 'status', 'attended_count', 'detected_count', 'delta', 'successful_samples_count', 'failed_samples_count', 'first_sampled_at', 'last_sampled_at', 'summarized_at'])]
class ScheduledClassPeopleCount extends Model
{
    use HasFactory;

    public const StatusMatched = 'matched';

    public const StatusMismatch = 'mismatch';

    public const StatusInsufficientData = 'insufficient_data';

    public const StatusNoCamera = 'no_camera';

    protected $attributes = [
        'status' => self::StatusInsufficientData,
        'attended_count' => 0,
        'successful_samples_count' => 0,
        'failed_samples_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attended_count' => 'integer',
            'detected_count' => 'integer',
            'delta' => 'integer',
            'successful_samples_count' => 'integer',
            'failed_samples_count' => 'integer',
            'first_sampled_at' => 'datetime',
            'last_sampled_at' => 'datetime',
            'summarized_at' => 'datetime',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
