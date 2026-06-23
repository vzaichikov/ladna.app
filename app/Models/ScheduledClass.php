<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use Database\Factories\ScheduledClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'location_id', 'room_id', 'class_type_id', 'trainer_id', 'schedule_series_id', 'title', 'description', 'starts_at', 'ends_at', 'capacity', 'booking_cutoff_minutes', 'is_generated', 'is_manually_modified', 'metadata', 'is_public', 'status'])]
class ScheduledClass extends Model
{
    /** @use HasFactory<ScheduledClassFactory> */
    use HasFactory;

    protected $attributes = [
        'is_generated' => false,
        'is_manually_modified' => false,
        'is_public' => true,
        'status' => 'scheduled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
            'is_generated' => 'boolean',
            'is_manually_modified' => 'boolean',
            'is_public' => 'boolean',
            'status' => ScheduledClassStatus::class,
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

    public function scheduleSeries(): BelongsTo
    {
        return $this->belongsTo(ScheduleSeries::class);
    }

    public function classBookings(): HasMany
    {
        return $this->hasMany(ClassBooking::class);
    }

    public function scopePublicUpcoming(Builder $query): Builder
    {
        return $query
            ->where('is_public', true)
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('starts_at', '>=', now())
            ->whereHas('account', fn (Builder $query) => $query
                ->where('status', AccountStatus::Active->value)
                ->where(function (Builder $query): void {
                    $query->whereNull('enabled_schedule_kinds')
                        ->orWhereJsonContains('enabled_schedule_kinds', ScheduleKind::GroupClass->value);
                }))
            ->whereHas('location', fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('room', fn (Builder $query) => $query
                ->where('is_active', true)
                ->whereColumn('rooms.account_id', 'scheduled_classes.account_id')
                ->whereColumn('rooms.location_id', 'scheduled_classes.location_id'))
            ->whereHas('classType', fn (Builder $query) => $query
                ->whereColumn('class_types.account_id', 'scheduled_classes.account_id')
                ->where('is_active', true)
                ->where('schedule_kind', ScheduleKind::GroupClass->value))
            ->where(function (Builder $query): void {
                $query->whereNull('schedule_series_id')
                    ->orWhereHas('scheduleSeries', fn (Builder $query) => $query->where('status', ScheduleSeriesStatus::Active->value));
            })
            ->orderBy('starts_at');
    }

    public function displayTimezone(): string
    {
        return $this->location?->timezone
            ?? $this->account?->timezone
            ?? config('app.timezone');
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    public function effectiveBookingCutoffMinutes(): ?int
    {
        return $this->booking_cutoff_minutes ?? $this->classType?->booking_cutoff_minutes;
    }
}
