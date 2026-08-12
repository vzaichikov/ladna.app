<?php

namespace App\Models;

use Database\Factories\FestivalBattleJudgeVoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'festival_category_id', 'festival_battle_match_id', 'festival_judge_assignment_id', 'selected_entry_id'])]
class FestivalBattleJudgeVote extends Model
{
    /** @use HasFactory<FestivalBattleJudgeVoteFactory> */
    use HasFactory;

    public function match(): BelongsTo
    {
        return $this->belongsTo(FestivalBattleMatch::class, 'festival_battle_match_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(FestivalJudgeAssignment::class, 'festival_judge_assignment_id');
    }

    public function selectedEntry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'selected_entry_id');
    }
}
