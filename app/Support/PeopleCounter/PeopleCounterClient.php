<?php

namespace App\Support\PeopleCounter;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PeopleCounterClient
{
    public function count(string $absoluteImagePath): PeopleCounterDetectionResult
    {
        $baseUrl = rtrim((string) config('services.people_counter.base_url', 'http://127.0.0.1:8710'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('People counter service is not configured.');
        }

        $stream = @fopen($absoluteImagePath, 'r');

        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open masked image for people detection.');
        }

        try {
            $payload = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->connectTimeout((int) config('services.people_counter.connect_timeout', 2))
                ->timeout((int) config('services.people_counter.timeout', 30))
                ->attach('image', $stream, basename($absoluteImagePath), ['Content-Type' => 'image/jpeg'])
                ->post('/count')
                ->throw()
                ->json();
        } finally {
            fclose($stream);
        }

        if (! is_array($payload) || ! is_numeric($payload['count'] ?? null)) {
            throw new RuntimeException('People counter service returned an invalid response.');
        }

        $detections = is_array($payload['detections'] ?? null) ? $payload['detections'] : [];
        $confidences = collect($detections)
            ->map(fn (mixed $detection): ?float => is_array($detection) && is_numeric($detection['confidence'] ?? null) ? (float) $detection['confidence'] : null)
            ->filter(fn (?float $confidence): bool => $confidence !== null);

        return new PeopleCounterDetectionResult(
            count: (int) $payload['count'],
            detections: $detections,
            payload: $payload,
            averageConfidence: $confidences->isEmpty() ? null : round((float) $confidences->avg(), 4),
        );
    }
}
