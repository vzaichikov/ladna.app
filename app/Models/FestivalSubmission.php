<?php

namespace App\Models;

use App\Enums\FestivalSubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['account_id', 'festival_entry_id', 'festival_entry_requirement_id', 'festival_portal_user_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'duration_seconds', 'value_json', 'status', 'reviewed_by', 'reviewed_at', 'review_notes'])]
class FestivalSubmission extends Model
{
    /** @var list<string> */
    public const INLINE_AUDIO_MIME_TYPES = [
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'audio/wav',
        'audio/x-wav',
    ];

    /** @var list<string> */
    public const INLINE_VIDEO_MIME_TYPES = [
        'video/mp4',
        'video/ogg',
        'video/webm',
    ];

    /** @var list<string> */
    public const INLINE_IMAGE_MIME_TYPES = [
        'image/avif',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    protected $attributes = ['disk' => 'local', 'status' => 'submitted'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'duration_seconds' => 'integer', 'value_json' => 'array', 'status' => FestivalSubmissionStatus::class, 'reviewed_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FestivalEntryRequirement::class, 'festival_entry_requirement_id');
    }

    /** @return list<string> */
    public static function playableMimeTypes(): array
    {
        return [...self::INLINE_AUDIO_MIME_TYPES, ...self::INLINE_VIDEO_MIME_TYPES];
    }

    /** @return list<string> */
    public static function inlinePreviewMimeTypes(): array
    {
        return [...self::playableMimeTypes(), ...self::INLINE_IMAGE_MIME_TYPES];
    }

    public function playbackKind(): ?string
    {
        $mimeType = Str::lower((string) $this->mime_type);

        return match (true) {
            in_array($mimeType, self::INLINE_AUDIO_MIME_TYPES, true) => 'audio',
            in_array($mimeType, self::INLINE_VIDEO_MIME_TYPES, true) => 'video',
            default => null,
        };
    }

    public function isInlinePreviewable(): bool
    {
        return in_array(Str::lower((string) $this->mime_type), self::inlinePreviewMimeTypes(), true);
    }
}
