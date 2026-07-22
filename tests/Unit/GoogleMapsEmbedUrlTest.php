<?php

namespace Tests\Unit;

use App\Rules\GoogleMapsEmbedUrl;
use PHPUnit\Framework\TestCase;

class GoogleMapsEmbedUrlTest extends TestCase
{
    public function test_it_accepts_supported_google_maps_embed_urls(): void
    {
        $supportedUrls = [
            'https://google.com/maps/embed?pb=example',
            'https://www.google.com/maps/embed/v1/place?key=example&q=Kyiv',
            'https://maps.google.com/maps/d/embed?mid=example',
            'https://www.google.com/maps?output=embed&q=Kyiv',
            'https://www.google.com:443/maps/embed?pb=example',
        ];

        foreach ($supportedUrls as $supportedUrl) {
            $this->assertSame([], $this->validationFailures($supportedUrl));
        }
    }

    public function test_it_rejects_non_embed_or_unsafe_urls(): void
    {
        $invalidUrls = [
            'not-a-url',
            'http://www.google.com/maps/embed?pb=example',
            'https://example.com/maps/embed?pb=example',
            'https://maps.app.goo.gl/example',
            'https://www.google.com/maps/place/example',
            'https://www.google.com/maps/embedevil?pb=example',
            'https://www.google.com/MAPS/EMBED?pb=example',
            'https://user@www.google.com/maps/embed?pb=example',
            'https://www.google.com:444/maps/embed?pb=example',
        ];

        foreach ($invalidUrls as $invalidUrl) {
            $this->assertSame(
                ['app.google_maps_embed_url_invalid'],
                $this->validationFailures($invalidUrl),
            );
        }
    }

    /** @return list<string> */
    private function validationFailures(mixed $value): array
    {
        $failures = [];

        (new GoogleMapsEmbedUrl)->validate(
            'google_maps_embed_url',
            $value,
            function (string $message) use (&$failures): object {
                $failures[] = $message;

                return new class
                {
                    public function translate(): static
                    {
                        return $this;
                    }
                };
            },
        );

        return $failures;
    }
}
