<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\FestivalParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['account_id', 'festival_portal_user_id', 'is_profile_owner', 'first_name', 'last_name', 'patronymic', 'date_of_birth', 'notes', 'archived_at'])]
class FestivalParticipant extends Model
{
    /** @use HasFactory<FestivalParticipantFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_profile_owner' => 'boolean', 'date_of_birth' => 'date', 'archived_at' => 'datetime'];
    }

    public function displayName(): string
    {
        return collect([$this->last_name, $this->first_name, $this->patronymic])->filter()->join(' ');
    }

    public function ageOn(CarbonInterface $date): int
    {
        return (int) $this->date_of_birth->diffInYears($date);
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
}
