<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

class GoogleMapsEmbedUrl implements ValidationRule
{
    /** @var list<string> */
    private const ALLOWED_HOSTS = [
        'google.com',
        'www.google.com',
        'maps.google.com',
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isGoogleMapsEmbedUrl($value)) {
            $fail('app.google_maps_embed_url_invalid')->translate();
        }
    }

    private function isGoogleMapsEmbedUrl(string $value): bool
    {
        try {
            $uri = Uri::of($value);
        } catch (Throwable) {
            return false;
        }

        $scheme = Str::lower($uri->scheme() ?? '');
        $host = Str::lower($uri->host() ?? '');

        if ($scheme !== 'https'
            || ! in_array($host, self::ALLOWED_HOSTS, true)
            || $uri->user() !== null
            || $uri->password() !== null
            || ! in_array($uri->port(), [null, 443], true)) {
            return false;
        }

        $path = $uri->path();
        $usesEmbedPath = $path === 'maps/embed'
            || Str::startsWith($path, 'maps/embed/')
            || $path === 'maps/d/embed'
            || Str::startsWith($path, 'maps/d/embed/');
        $usesOutputEmbed = $path === 'maps' && $uri->query()->get('output') === 'embed';

        return $usesEmbedPath || $usesOutputEmbed;
    }
}
