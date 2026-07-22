<?php

namespace Tests\Unit;

use App\Support\GoogleMapsEmbedUrlNormalizer;
use PHPUnit\Framework\TestCase;

class GoogleMapsEmbedUrlNormalizerTest extends TestCase
{
    public function test_it_normalizes_blank_values_to_null(): void
    {
        $normalizer = new GoogleMapsEmbedUrlNormalizer;

        $this->assertNull($normalizer->normalize(null));
        $this->assertNull($normalizer->normalize('   '));
    }

    public function test_it_trims_direct_urls(): void
    {
        $normalizer = new GoogleMapsEmbedUrlNormalizer;

        $this->assertSame(
            'https://www.google.com/maps?output=embed&q=Kyiv',
            $normalizer->normalize('  https://www.google.com/maps?output=embed&q=Kyiv  '),
        );
    }

    public function test_it_extracts_the_source_from_google_iframe_html(): void
    {
        $normalizer = new GoogleMapsEmbedUrlNormalizer;
        $iframe = '<iframe src="https://www.google.com/maps/embed?pb=!1m18!2d24.7066!3d48.9368" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>';

        $this->assertSame(
            'https://www.google.com/maps/embed?pb=!1m18!2d24.7066!3d48.9368',
            $normalizer->normalize($iframe),
        );
    }

    public function test_it_extracts_single_quoted_source_regardless_of_attribute_order(): void
    {
        $normalizer = new GoogleMapsEmbedUrlNormalizer;
        $iframe = "<iframe loading='lazy' width='600' src='https://www.google.com/maps/d/embed?mid=example'></iframe>";

        $this->assertSame(
            'https://www.google.com/maps/d/embed?mid=example',
            $normalizer->normalize($iframe),
        );
    }

    public function test_it_decodes_html_entities_in_the_source(): void
    {
        $normalizer = new GoogleMapsEmbedUrlNormalizer;
        $iframe = '<iframe src="https://www.google.com/maps?output=embed&amp;q=Kyiv"></iframe>';

        $this->assertSame(
            'https://www.google.com/maps?output=embed&q=Kyiv',
            $normalizer->normalize($iframe),
        );
    }

    public function test_it_leaves_malformed_iframe_html_unchanged_for_validation(): void
    {
        $normalizer = new GoogleMapsEmbedUrlNormalizer;
        $missingSource = '<iframe width="600"></iframe>';
        $missingClosingTag = '<iframe src="https://www.google.com/maps/embed?pb=example">';

        $this->assertSame($missingSource, $normalizer->normalize($missingSource));
        $this->assertSame($missingClosingTag, $normalizer->normalize($missingClosingTag));
    }
}
