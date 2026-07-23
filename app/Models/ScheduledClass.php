<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonInterface;
use Database\Factories\ScheduledClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['account_id', 'location_id', 'room_id', 'class_type_id', 'trainer_id', 'schedule_series_id', 'title', 'description', 'starts_at', 'ends_at', 'capacity', 'booking_cutoff_minutes', 'cancellation_cutoff_minutes', 'is_generated', 'is_manually_modified', 'metadata', 'is_public', 'status'])]
class ScheduledClass extends Model
{
    /** @use HasFactory<ScheduledClassFactory> */
    use HasFactory;

    public const STUDIO_CANCELLATION_GRACE_MINUTES = 60;

    public const MANUAL_TRAINER_OVERRIDE_METADATA_KEY = 'manual_trainer_override';

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

    public function additionalTrainers(): BelongsToMany
    {
        return $this->belongsToMany(Trainer::class, 'scheduled_class_additional_trainer')
            ->withPivot('account_id')
            ->withTimestamps()
            ->orderBy('trainers.name');
    }

    public function scheduleSeries(): BelongsTo
    {
        return $this->belongsTo(ScheduleSeries::class);
    }

    public function classBookings(): HasMany
    {
        return $this->hasMany(ClassBooking::class);
    }

    public function visibleClassBookings(): HasMany
    {
        return $this->classBookings()->notCorrectedRemoved();
    }

    public function classBookingCorrections(): HasMany
    {
        return $this->hasMany(ClassBookingCorrection::class);
    }

    public function trainerChanges(): HasMany
    {
        return $this->hasMany(ScheduledClassTrainerChange::class)->latest('id');
    }

    public function peopleCounterSamples(): HasMany
    {
        return $this->hasMany(PeopleCounterSample::class);
    }

    public function peopleCount(): HasOne
    {
        return $this->hasOne(ScheduledClassPeopleCount::class);
    }

    public function latestSuccessfulPeopleCounterSample(): HasOne
    {
        return $this->hasOne(PeopleCounterSample::class)
            ->where('status', PeopleCounterSample::StatusSucceeded)
            ->latestOfMany('captured_at');
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(ScheduledClassCancellation::class);
    }

    public function activeCancellation(): HasOne
    {
        return $this->hasOne(ScheduledClassCancellation::class)
            ->whereNull('restored_at')
            ->latestOfMany();
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

    public function scopePeopleCounterTrackable(Builder $query): Builder
    {
        return $query
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->whereDoesntHave('cancellations', fn (Builder $query) => $query->whereNull('restored_at'))
            ->where(function (Builder $query): void {
                $query
                    ->where('is_generated', false)
                    ->orWhere('is_manually_modified', true)
                    ->orWhereHas('classBookings', fn (Builder $query) => $query
                        ->notCorrectedRemoved()
                        ->whereIn('status', self::peopleCounterAssignedBookingStatuses()));
            });
    }

    /**
     * @return array<int, string>
     */
    public static function peopleCounterAssignedBookingStatuses(): array
    {
        return [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
            ClassBookingStatus::NoShow->value,
        ];
    }

    public function displayTimezone(): string
    {
        return $this->location?->timezone
            ?? $this->account?->timezone
            ?? config('app.timezone');
    }

    public function peopleCounterTrainerAdjustment(): int
    {
        $scheduleKind = $this->classType?->schedule_kind;

        if (! $scheduleKind instanceof ScheduleKind) {
            return 0;
        }

        if ($scheduleKind === ScheduleKind::InternalClass) {
            return ($this->trainer_id ? 1 : 0) + $this->additionalTrainerIds()->count();
        }

        return (int) (ScheduleKindRegistry::get($scheduleKind)['people_counter_trainer_adjustment'] ?? 0);
    }

    /**
     * @return Collection<int, int>
     */
    public function additionalTrainerIds(): Collection
    {
        if ($this->relationLoaded('additionalTrainers')) {
            return $this->additionalTrainers
                ->pluck('id')
                ->map(fn (mixed $trainerId): int => (int) $trainerId)
                ->values();
        }

        return $this->additionalTrainers()
            ->pluck('trainers.id')
            ->map(fn (mixed $trainerId): int => (int) $trainerId)
            ->values();
    }

    public function isAssignedToTrainer(int $trainerId): bool
    {
        if ($this->trainer_id === $trainerId) {
            return true;
        }

        return $this->relationLoaded('additionalTrainers')
            ? $this->additionalTrainers->contains('id', $trainerId)
            : $this->additionalTrainers()->whereKey($trainerId)->exists();
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    public function displayTitle(): string
    {
        if ($this->isAnytimeRoomRental()) {
            return __('app.room_rental_duration_title', ['minutes' => $this->durationMinutes()]);
        }

        return $this->title;
    }

    public function isAnytimeRoomRental(): bool
    {
        return $this->classType?->schedule_kind === ScheduleKind::RoomRental
            && ($this->metadata['rental_mode'] ?? null) === 'anytime';
    }

    public function displayStatusValue(): string
    {
        if ($this->status !== ScheduledClassStatus::Scheduled) {
            return $this->status->value;
        }

        $now = now();

        if ($this->ends_at->lessThanOrEqualTo($now)) {
            return 'ended';
        }

        if ($this->starts_at->lessThanOrEqualTo($now)) {
            return 'in_progress';
        }

        return ScheduledClassStatus::Scheduled->value;
    }

    public function displayStatusLabelKey(): string
    {
        return 'app.'.$this->displayStatusValue();
    }

    public function displayStatusBadgeClass(): string
    {
        return match ($this->displayStatusValue()) {
            'cancelled' => 'crm-status-danger',
            'draft', 'ended' => 'crm-status-muted',
            'in_progress' => 'crm-status-active',
            default => 'crm-status-scheduled',
        };
    }

    /**
     * @return array<int, string>
     */
    public function displayTypeLabels(): array
    {
        $title = $this->normalizeDisplayLabel($this->displayTitle());
        $seen = [$title => true];
        $labels = [$this->classType?->activityDirection?->name];

        if (! $this->isAnytimeRoomRental()) {
            $labels[] = $this->classType?->name;
        }

        return collect($labels)
            ->filter(fn (?string $label): bool => filled($label))
            ->map(fn (string $label): string => trim($label))
            ->filter(function (string $label) use (&$seen): bool {
                $normalized = $this->normalizeDisplayLabel($label);

                if ($normalized === '' || isset($seen[$normalized])) {
                    return false;
                }

                $seen[$normalized] = true;

                return true;
            })
            ->values()
            ->all();
    }

    private function normalizeDisplayLabel(?string $label): string
    {
        return Str::of((string) $label)
            ->squish()
            ->lower()
            ->toString();
    }

    public function effectiveBookingCutoffMinutes(): ?int
    {
        return $this->booking_cutoff_minutes ?? $this->classType?->booking_cutoff_minutes;
    }

    public function bookingClosesAt(): ?Carbon
    {
        $cutoffMinutes = $this->effectiveBookingCutoffMinutes();

        if ($cutoffMinutes === null) {
            return null;
        }

        return $this->starts_at->copy()->subMinutes((int) $cutoffMinutes);
    }

    public function isBookingOpen(): bool
    {
        if (! $this->acceptsCustomerBookings()) {
            return false;
        }

        $closesAt = $this->bookingClosesAt();

        return $closesAt === null || now()->lessThan($closesAt);
    }

    public function effectiveCancellationCutoffMinutes(): ?int
    {
        return $this->cancellation_cutoff_minutes ?? $this->classType?->cancellation_cutoff_minutes;
    }

    public function studioCancellationClosesAt(): Carbon
    {
        return $this->ends_at->copy()->addMinutes(self::STUDIO_CANCELLATION_GRACE_MINUTES);
    }

    public function isStudioCancellationOpen(): bool
    {
        return now()->lessThanOrEqualTo($this->studioCancellationClosesAt());
    }

    public function canManuallyCorrectTrainer(?CarbonInterface $at = null): bool
    {
        if (! in_array($this->classType?->schedule_kind, [ScheduleKind::GroupClass, ScheduleKind::PrivateLesson], true)) {
            return false;
        }

        return ! $this->is_generated || $this->ends_at->lessThanOrEqualTo($at ?? now());
    }

    public function acceptsCustomerBookings(): bool
    {
        $scheduleKind = $this->classType?->schedule_kind;

        return $scheduleKind instanceof ScheduleKind
            && ScheduleKindRegistry::hasCapability($scheduleKind, 'customer_bookable');
    }

    public function supportsClassPasses(): bool
    {
        $scheduleKind = $this->classType?->schedule_kind;

        return $scheduleKind instanceof ScheduleKind
            && ScheduleKindRegistry::hasCapability($scheduleKind, 'class_pass_eligible');
    }

    public function isFullyEditableOccurrence(): bool
    {
        $scheduleKind = $this->classType?->schedule_kind;

        return $scheduleKind instanceof ScheduleKind
            && ScheduleKindRegistry::hasCapability($scheduleKind, 'full_occurrence_editable')
            && $this->status === ScheduledClassStatus::Scheduled
            && $this->starts_at->isFuture();
    }
}
