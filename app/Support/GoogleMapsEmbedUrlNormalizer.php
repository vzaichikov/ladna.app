<?php

namespace App\Support;

use DOMDocument;
use Illuminate\Support\Str;

class GoogleMapsEmbedUrlNormalizer
{
    public function normalize(?string $value): ?string
    {
        $normalizedValue = Str::of($value ?? '')->trim()->toString();

        if ($normalizedValue === '') {
            return null;
        }

        if (! Str::contains(Str::lower($normalizedValue), '<iframe')) {
            return $normalizedValue;
        }

        if (preg_match('/^<iframe\b[^>]*>.*<\/iframe>$/is', $normalizedValue) !== 1) {
            return $normalizedValue;
        }

        $document = new DOMDocument;
        $previousLibxmlState = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML($normalizedValue, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET)) {
                return $normalizedValue;
            }

            $iframe = $document->getElementsByTagName('iframe')->item(0);

            if ($iframe === null || ! $iframe->hasAttribute('src')) {
                return $normalizedValue;
            }

            $normalizedSource = Str::of($iframe->getAttribute('src'))->trim()->toString();

            return $normalizedSource !== '' ? $normalizedSource : $normalizedValue;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
    }
}
