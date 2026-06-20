<?php

namespace App\Support;

use Closure;

class SlugGenerator
{
    /**
     * @var array<string, string>
     */
    private const CYRILLIC_MAP = [
        'а' => 'a',
        'б' => 'b',
        'в' => 'v',
        'г' => 'h',
        'ґ' => 'g',
        'д' => 'd',
        'е' => 'e',
        'є' => 'ye',
        'ё' => 'yo',
        'ж' => 'zh',
        'з' => 'z',
        'и' => 'y',
        'і' => 'i',
        'ї' => 'yi',
        'й' => 'y',
        'к' => 'k',
        'л' => 'l',
        'м' => 'm',
        'н' => 'n',
        'о' => 'o',
        'п' => 'p',
        'р' => 'r',
        'с' => 's',
        'т' => 't',
        'у' => 'u',
        'ф' => 'f',
        'х' => 'kh',
        'ц' => 'ts',
        'ч' => 'ch',
        'ш' => 'sh',
        'щ' => 'shch',
        'ъ' => '',
        'ы' => 'y',
        'ь' => '',
        'э' => 'e',
        'ю' => 'yu',
        'я' => 'ya',
    ];

    public static function base(?string $source, string $fallback): string
    {
        $source = mb_strtolower(trim((string) $source));
        $source = strtr($source, self::CYRILLIC_MAP);
        $source = preg_replace('/[^a-z0-9]+/u', '-', $source) ?? '';
        $source = trim($source, '-');

        return $source !== '' ? $source : $fallback;
    }

    public static function unique(?string $source, string $fallback, Closure $isTaken): string
    {
        $base = self::base($source, $fallback);
        $candidate = $base;
        $suffix = 2;

        while ($isTaken($candidate)) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
