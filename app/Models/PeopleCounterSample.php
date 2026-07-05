<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'scheduled_class_id', 'unknown_presence_interval_id', 'location_id', 'room_id', 'captured_at', 'status', 'failure_reason', 'original_image_path', 'masked_image_path', 'image_width', 'image_height', 'detected_count', 'average_confidence', 'detections', 'response_payload'])]
class PeopleCounterSample extends Model
{
    use HasFactory;

    public const StatusSucceeded = 'succeeded';

    public const StatusCaptureFailed = 'capture_failed';

    public const StatusDetectionFailed = 'detection_failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'detected_count' => 'integer',
            'average_confidence' => 'decimal:4',
            'detections' => 'array',
            'response_payload' => 'array',
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

    public function unknownPresenceInterval(): BelongsTo
    {
        return $this->belongsTo(UnknownPresenceInterval::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
