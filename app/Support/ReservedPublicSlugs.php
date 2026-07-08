<?php

namespace App\Support;

class ReservedPublicSlugs
{
    /**
     * @var list<string>
     */
    private const SLUGS = [
        'api',
        'api-docs',
        'app',
        'app-version',
        'app-version-json',
        'customer',
        'dashboard',
        'demo',
        'en',
        'help',
        'locale',
        'login',
        'logout',
        'manifest',
        'manifest-webmanifest',
        'offline',
        'offline-html',
        'platform',
        'privacy-en-html',
        'privacy-ua-html',
        'register',
        'service-worker',
        'terms-en-html',
        'terms-ua-html',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::SLUGS;
    }

    public static function isReserved(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $slug = SlugGenerator::base((string) $value, '');

        return in_array($slug, self::SLUGS, true);
    }
}
