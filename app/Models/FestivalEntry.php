<?php

namespace App\Models;

use App\Enums\FestivalChargeStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalQualificationStatus;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementStatus;
use Database\Factories\FestivalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['account_id', 'festival_edition_id', 'festival_portal_user_id', 'festival_category_id', 'code', 'entry_name', 'act_title', 'act_description', 'track_artist', 'track_title', 'normalized_track_key', 'track_reserved_at', 'comments', 'status', 'qualification_status', 'submitted_at', 'reviewed_at', 'reviewed_by', 'review_notes', 'accepted_at', 'registration_completed_at', 'rejected_at', 'withdrawn_at'])]
class FestivalEntry extends Model
{
    /** @use HasFactory<FestivalEntryFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'draft', 'qualification_status' => 'not_required'];

    protected function casts(): array
    {
        return [
            'status' => FestivalEntryStatus::class,
            'qualification_status' => FestivalQualificationStatus::class,
            'submitted_at' => 'datetime',
            'track_reserved_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'registration_completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        $requirementsReady = ! $this->requirements()
            ->whereHas('definition', fn ($query) => $query
                ->where('is_required', true)
                ->orWhere('input_type', FestivalRequirementInputType::Agreement->value))
            ->whereNotIn('status', [FestivalRequirementStatus::Accepted->value, FestivalRequirementStatus::Waived->value])
            ->exists();
        $chargesReady = ! $this->charges()->whereNotIn('status', [FestivalChargeStatus::Paid->value, FestivalChargeStatus::Cancelled->value])->exists();
        $qualificationReady = in_array($this->qualification_status, [FestivalQualificationStatus::NotRequired, FestivalQualificationStatus::Passed], true);

        return $this->status === FestivalEntryStatus::Accepted
            && $requirementsReady
            && $chargesReady
            && $qualificationReady
            && $this->scheduleSlots()
                ->where('type', 'performance')
                ->whereNotNull('starts_at')
                ->whereNotNull('ends_at')
                ->exists();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(FestivalPortalUser::class, 'festival_portal_user_id');
    }

    public function festivalPortalUser(): BelongsTo
    {
        return $this->portalUser();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FestivalCategory::class, 'festival_category_id');
    }

    public function festivalCategory(): BelongsTo
    {
        return $this->category();
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(FestivalParticipant::class, 'festival_entry_participant')
            ->withPivot(['account_id', 'sort_order'])
            ->orderByPivot('sort_order');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(FestivalEntryStep::class)
            ->with('workflowStep')
            ->orderBy(
                FestivalWorkflowStep::query()
                    ->select('sort_order')
                    ->whereColumn('festival_workflow_steps.id', 'festival_entry_steps.festival_workflow_step_id'),
            )
            ->orderBy('festival_entry_steps.id');
    }

    public function festivalEntrySteps(): HasMany
    {
        return $this->steps();
    }

    public function chargeAdjustments(): HasMany
    {
        return $this->hasMany(FestivalChargeAdjustment::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(FestivalEntryRequirement::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(FestivalCharge::class);
    }

    public function scheduleSlots(): HasMany
    {
        return $this->hasMany(FestivalScheduleSlot::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(FestivalActivityLog::class);
    }

    public function scoreSheets(): HasMany
    {
        return $this->hasMany(FestivalScoreSheet::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(FestivalPenalty::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(FestivalResult::class);
    }
}
