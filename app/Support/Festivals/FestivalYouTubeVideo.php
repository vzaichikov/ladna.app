<?php

namespace App\Support\Festivals;

class FestivalYouTubeVideo
{
    /** @var list<string> */
    private const YOUTUBE_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
    ];

    /** @var list<string> */
    private const PRIVACY_HOSTS = [
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    ];

    /** @var list<string> */
    private const SHORT_HOSTS = [
        'youtu.be',
        'www.youtu.be',
    ];

    public static function idFromUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $videoId = null;

        if (in_array($host, self::SHORT_HOSTS, true)) {
            if (preg_match('#^/([^/]+)$#', $path, $matches) === 1) {
                $videoId = rawurldecode($matches[1]);
            }
        } elseif (in_array($host, self::YOUTUBE_HOSTS, true)) {
            if ($path === '/watch') {
                $videoId = self::videoIdFromQuery((string) ($parts['query'] ?? ''));
            } elseif (preg_match('#^/(?:live|embed)/([^/]+)$#', $path, $matches) === 1) {
                $videoId = rawurldecode($matches[1]);
            }
        } elseif (in_array($host, self::PRIVACY_HOSTS, true)
            && preg_match('#^/embed/([^/]+)$#', $path, $matches) === 1) {
            $videoId = rawurldecode($matches[1]);
        }

        return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1
            ? $videoId
            : null;
    }

    public static function watchUrl(?string $videoId): string
    {
        return self::validId($videoId) ? 'https://www.youtube.com/watch?v='.rawurlencode($videoId) : '';
    }

    public static function embedUrl(?string $videoId): string
    {
        return self::validId($videoId)
            ? 'https://www.youtube-nocookie.com/embed/'.rawurlencode($videoId).'?autoplay=1&playsinline=1&rel=0'
            : '';
    }

    private static function validId(?string $videoId): bool
    {
        return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1;
    }

    private static function videoIdFromQuery(string $query): ?string
    {
        $matches = [];
        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (rawurldecode($key) === 'v') {
                $matches[] = rawurldecode($value);
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
