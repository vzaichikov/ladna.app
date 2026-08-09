<?php

namespace App\Models;

use App\Enums\FestivalSubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_entry_id', 'festival_entry_requirement_id', 'festival_portal_user_id', 'version', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'duration_seconds', 'status', 'reviewed_by', 'reviewed_at', 'review_notes'])]
class FestivalSubmission extends Model
{
    protected $attributes = ['disk' => 'local', 'status' => 'submitted'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'size_bytes' => 'integer', 'duration_seconds' => 'integer', 'status' => FestivalSubmissionStatus::class, 'reviewed_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FestivalEntryRequirement::class, 'festival_entry_requirement_id');
    }
}
