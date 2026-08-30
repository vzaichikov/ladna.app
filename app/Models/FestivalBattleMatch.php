<?php

namespace App\Models;

use App\Enums\FestivalBattleMatchStatus;
use Database\Factories\FestivalBattleMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_category_id', 'round', 'position', 'entry_a_id', 'entry_b_id', 'next_match_id', 'next_position', 'status', 'audience_votes_a', 'audience_votes_b', 'judge_votes_a', 'judge_votes_b', 'jury_percentage_a', 'jury_percentage_b', 'audience_percentage_a', 'audience_percentage_b', 'combined_percentage_a', 'combined_percentage_b', 'winner_entry_id', 'decided_by', 'decided_by_account_api_token_id', 'tie_break_reason', 'finalized_at'])]
class FestivalBattleMatch extends Model
{
    /** @use HasFactory<FestivalBattleMatchFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'position' => 'integer',
            'status' => FestivalBattleMatchStatus::class,
            'audience_votes_a' => 'integer',
            'audience_votes_b' => 'integer',
            'judge_votes_a' => 'integer',
            'judge_votes_b' => 'integer',
            'jury_percentage_a' => 'decimal:4',
            'jury_percentage_b' => 'decimal:4',
            'audience_percentage_a' => 'decimal:4',
            'audience_percentage_b' => 'decimal:4',
            'combined_percentage_a' => 'decimal:4',
            'combined_percentage_b' => 'decimal:4',
            'finalized_at' => 'datetime',
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FestivalCategory::class, 'festival_category_id');
    }

    public function entryA(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'entry_a_id');
    }

    public function entryB(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'entry_b_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'winner_entry_id');
    }

    public function decidingApiToken(): BelongsTo
    {
        return $this->belongsTo(AccountApiToken::class, 'decided_by_account_api_token_id');
    }

    public function nextMatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_match_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(FestivalBattleJudgeVote::class);
    }
}
