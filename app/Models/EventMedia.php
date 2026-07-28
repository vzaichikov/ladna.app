<?php

namespace App\Models;

use Database\Factories\EventMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['account_id', 'event_id', 'kind', 'image_path', 'external_url', 'alt_text', 'caption', 'sort_order', 'is_cover'])]
class EventMedia extends Model
{
    /** @use HasFactory<EventMediaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_cover' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function embedUrl(): ?string
    {
        $url = (string) $this->external_url;
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if (in_array($host, ['youtube.com', 'www.youtube.com'], true) && parse_url($url, PHP_URL_QUERY)) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return filled($query['v'] ?? null) ? 'https://www.youtube-nocookie.com/embed/'.rawurlencode((string) $query['v']) : null;
        }

        if ($host === 'youtu.be' && $path !== '') {
            return 'https://www.youtube-nocookie.com/embed/'.rawurlencode($path);
        }

        if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && ctype_digit($path)) {
            return 'https://player.vimeo.com/video/'.$path;
        }

        return null;
    }
}
