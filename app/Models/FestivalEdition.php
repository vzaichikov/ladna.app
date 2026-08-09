<?php

namespace App\Models;

use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalRegistrationStatus;
use Database\Factories\FestivalEditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['account_id', 'festival_series_id', 'slug', 'title', 'status', 'registration_status', 'summary', 'description_html', 'rules_html', 'venue_name', 'venue_address', 'venue_map_url', 'venue_directions', 'timezone', 'currency', 'starts_at', 'ends_at', 'age_reference_date', 'registration_opens_at', 'registration_closes_at', 'published_at', 'completed_at', 'cancelled_at', 'archived_at'])]
class FestivalEdition extends Model
{
    /** @use HasFactory<FestivalEditionFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'draft', 'registration_status' => 'closed', 'currency' => 'UAH'];

    protected function casts(): array
    {
        return [
            'status' => FestivalEditionStatus::class,
            'registration_status' => FestivalRegistrationStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'age_reference_date' => 'date',
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'published_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', [FestivalEditionStatus::Published->value, FestivalEditionStatus::InProgress->value, FestivalEditionStatus::Completed->value]);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('ends_at', '>=', now());
    }

    public function registrationIsOpen(): bool
    {
        return $this->registration_status === FestivalRegistrationStatus::Open
            && (! $this->registration_opens_at || $this->registration_opens_at->isPast())
            && (! $this->registration_closes_at || $this->registration_closes_at->isFuture())
            && in_array($this->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress], true);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(FestivalSeries::class, 'festival_series_id');
    }

    public function festivalSeries(): BelongsTo
    {
        return $this->series();
    }

    public function sections(): HasMany
    {
        return $this->hasMany(FestivalContentSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FestivalDocument::class)->orderBy('sort_order')->orderBy('id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(FestivalMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function coverMedia(): HasOne
    {
        return $this->hasOne(FestivalMedia::class)->where('is_cover', true)->latestOfMany();
    }

    public function stages(): HasMany
    {
        return $this->hasMany(FestivalStage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function axes(): HasMany
    {
        return $this->hasMany(FestivalClassificationAxis::class)->orderBy('sort_order')->orderBy('id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(FestivalCategory::class);
    }

    public function festivalCategories(): HasMany
    {
        return $this->categories();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FestivalEntry::class);
    }

    public function festivalEntries(): HasMany
    {
        return $this->entries();
    }

    public function results(): HasMany
    {
        return $this->hasMany(FestivalResult::class);
    }

    public function scheduleSlots(): HasMany
    {
        return $this->hasMany(FestivalScheduleSlot::class);
    }

    public function festivalScheduleSlots(): HasMany
    {
        return $this->scheduleSlots();
    }

    public function festivalEntryRequirements(): HasManyThrough
    {
        return $this->hasManyThrough(FestivalEntryRequirement::class, FestivalEntry::class, 'festival_edition_id', 'festival_entry_id');
    }

    public function festivalCharges(): HasManyThrough
    {
        return $this->hasManyThrough(FestivalCharge::class, FestivalEntry::class, 'festival_edition_id', 'festival_entry_id');
    }

    public function festivalScoreSheets(): HasManyThrough
    {
        return $this->hasManyThrough(FestivalScoreSheet::class, FestivalEntry::class, 'festival_edition_id', 'festival_entry_id');
    }

    public function judgeAssignments(): HasMany
    {
        return $this->hasMany(FestivalJudgeAssignment::class);
    }

    public function festivalTickets(): HasMany
    {
        return $this->hasMany(FestivalTicket::class);
    }

    public function admissionTypes(): HasMany
    {
        return $this->hasMany(FestivalAdmissionType::class)->orderBy('sort_order')->orderBy('id');
    }

    public function ticketOrders(): HasMany
    {
        return $this->hasMany(FestivalTicketOrder::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FestivalTicket::class);
    }

    public function purchase(): HasOne
    {
        return $this->hasOne(FestivalEditionPurchase::class);
    }
}
