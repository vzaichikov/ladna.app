<?php

namespace App\Support;

final class ApplicationVersion
{
    private static ?string $version = null;

    public static function current(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        $path = base_path('VERSION');

        if (! is_file($path)) {
            return self::$version = '0.0.0';
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return self::$version = '0.0.0';
        }

        $version = trim($contents);

        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            return self::$version = '0.0.0';
        }

        return self::$version = $version;
    }

    public static function revision(?string $manifestPath = null): string
    {
        $version = self::current();
        $fingerprint = self::manifestFingerprint($manifestPath ?? public_path('build/manifest.json'));

        if ($fingerprint === null) {
            return $version;
        }

        return $version.'+'.$fingerprint;
    }

    public static function manifestFingerprint(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return substr(hash('sha256', $contents), 0, 12);
    }
}
