<?php

namespace App\Rules;

use App\Support\Festivals\FestivalYouTubeVideo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class FestivalYouTubeUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || FestivalYouTubeVideo::idFromUrl($value) === null) {
            $fail('app.festival_stream_youtube_url_invalid')->translate();
        }
    }
}
