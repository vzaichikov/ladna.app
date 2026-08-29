<?php

namespace App\Models;

use App\Enums\FestivalTeamMemberType;
use Carbon\CarbonInterface;
use Database\Factories\FestivalParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_portal_user_id', 'is_profile_owner', 'member_type', 'first_name', 'last_name', 'patronymic', 'date_of_birth', 'notes', 'photo_path', 'archived_at'])]
class FestivalParticipant extends Model
{
    /** @use HasFactory<FestivalParticipantFactory> */
    use HasFactory;

    protected $attributes = [
        'member_type' => 'performer',
    ];

    protected function casts(): array
    {
        return [
            'is_profile_owner' => 'boolean',
            'member_type' => FestivalTeamMemberType::class,
            'date_of_birth' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function displayName(): string
    {
        return collect([$this->last_name, $this->first_name, $this->patronymic])->filter()->join(' ');
    }

    public function ageOn(CarbonInterface $date): int
    {
        return (int) $this->date_of_birth->diffInYears($date);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopePerformers(Builder $query): Builder
    {
        return $query->where('member_type', FestivalTeamMemberType::Performer->value);
    }

    public function scopeHelpers(Builder $query): Builder
    {
        return $query->where('member_type', FestivalTeamMemberType::Helper->value);
    }

    public function resolvedPhotoPath(): ?string
    {
        if ($this->is_profile_owner) {
            return $this->portalUser?->avatar_path;
        }

        return $this->photo_path;
    }

    public function isInUse(): bool
    {
        return $this->entries()->exists()
            || $this->helperRequirements()->exists()
            || $this->entrancePasses()->exists()
            || $this->nominations()->exists();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'festival_portal_user_id');
    }

    public function festivalPortalUser(): BelongsTo
    {
        return $this->portalUser();
    }

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(FestivalEntry::class, 'festival_entry_participant')->withPivot(['account_id', 'sort_order']);
    }

    public function nominations(): BelongsToMany
    {
        return $this->belongsToMany(FestivalNomination::class, 'festival_nomination_participant')
            ->withPivot('account_id')
            ->withTimestamps();
    }

    public function helperRequirements(): BelongsToMany
    {
        return $this->belongsToMany(
            FestivalEntryRequirement::class,
            'festival_entry_requirement_helper',
            'festival_participant_id',
            'festival_entry_requirement_id',
        )->withPivot('sort_order');
    }

    public function entrancePasses(): HasMany
    {
        return $this->hasMany(FestivalEntrancePass::class);
    }

    public function hasCheckedInFestivalTicket(FestivalEdition $edition): bool
    {
        return FestivalTicket::query()
            ->where('festival_edition_id', $edition->id)
            ->where('festival_participant_id', $this->id)
            ->where('is_checked_in', true)
            ->exists();
    }
}
