<?php

namespace Tests\Unit;

use App\Support\Festivals\FestivalYouTubeVideo;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FestivalYouTubeVideoTest extends TestCase
{
    #[DataProvider('validUrls')]
    public function test_it_extracts_an_id_only_from_supported_youtube_urls(string $url): void
    {
        $this->assertSame('dQw4w9WgXcQ', FestivalYouTubeVideo::idFromUrl($url));
    }

    /** @return array<string, array{string}> */
    public static function validUrls(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'mobile watch with share query' => ['https://m.youtube.com/watch?si=abc&v=dQw4w9WgXcQ'],
            'live' => ['https://youtube.com/live/dQw4w9WgXcQ?si=abc'],
            'short host' => ['https://youtu.be/dQw4w9WgXcQ?si=abc'],
            'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ'],
            'privacy embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_it_rejects_unsafe_or_ambiguous_urls(string $url): void
    {
        $this->assertNull(FestivalYouTubeVideo::idFromUrl($url));
    }

    /** @return array<string, array{string}> */
    public static function invalidUrls(): array
    {
        return [
            'http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'lookalike host' => ['https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ'],
            'credentials' => ['https://user:pass@youtube.com/watch?v=dQw4w9WgXcQ'],
            'username only' => ['https://user@youtube.com/watch?v=dQw4w9WgXcQ'],
            'nonstandard port' => ['https://youtube.com:444/watch?v=dQw4w9WgXcQ'],
            'shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ'],
            'extra path' => ['https://youtu.be/dQw4w9WgXcQ/more'],
            'duplicate video id' => ['https://youtube.com/watch?v=dQw4w9WgXcQ&v=aaaaaaaaaaa'],
            'missing video id' => ['https://youtube.com/watch?feature=share'],
            'invalid video id' => ['https://youtube.com/watch?v=not-valid'],
            'fragment' => ['https://youtu.be/dQw4w9WgXcQ#fragment'],
            'iframe html' => ['<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>'],
            'privacy host watch path' => ['https://www.youtube-nocookie.com/watch?v=dQw4w9WgXcQ'],
        ];
    }

    public function test_it_builds_only_canonical_server_owned_urls(): void
    {
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', FestivalYouTubeVideo::watchUrl('dQw4w9WgXcQ'));
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&playsinline=1&rel=0', FestivalYouTubeVideo::embedUrl('dQw4w9WgXcQ'));
        $this->assertSame('', FestivalYouTubeVideo::embedUrl('invalid'));
    }
}
