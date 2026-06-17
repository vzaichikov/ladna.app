<?php

namespace App\Models;

use App\Enums\ScheduleSeriesStatus;
use Database\Factories\ScheduleSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'location_id', 'room_id', 'class_type_id', 'trainer_id', 'title', 'description', 'weekday', 'start_time', 'start_date', 'end_date', 'capacity', 'duration_minutes', 'booking_cutoff_minutes', 'status', 'generated_until', 'generated_at'])]
class ScheduleSeries extends Model
{
    /** @use HasFactory<ScheduleSeriesFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'generated_until' => 'date',
            'generated_at' => 'datetime',
            'status' => ScheduleSeriesStatus::class,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function classType(): BelongsTo
    {
        return $this->belongsTo(ClassType::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function scheduledClasses(): HasMany
    {
        return $this->hasMany(ScheduledClass::class);
    }

    public function effectiveTitle(): string
    {
        return $this->title ?: $this->classType->name;
    }

    public function effectiveDescription(): ?string
    {
        return $this->description ?: $this->classType->description;
    }

    public function effectiveDurationMinutes(): int
    {
        return $this->duration_minutes ?: $this->classType->default_duration_minutes;
    }

    public function effectiveCapacity(): ?int
    {
        return $this->capacity ?? $this->classType->default_capacity ?? $this->room->capacity;
    }

    public function effectiveBookingCutoffMinutes(): ?int
    {
        return $this->booking_cutoff_minutes ?? $this->classType->booking_cutoff_minutes;
    }
}
