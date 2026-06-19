<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Database\QueryException;

class SystemAppearance
{
    public const FontSettingKey = 'appearance.font_family';

    /**
     * @return array<string, array{label: string, google_family: string, css_family: string}>
     */
    public static function fontOptions(): array
    {
        return config('appearance.fonts', []);
    }

    public static function currentFontKey(): string
    {
        try {
            $storedFont = SystemSetting::stringValue(self::FontSettingKey, self::defaultFontKey());
        } catch (QueryException) {
            $storedFont = self::defaultFontKey();
        }

        return is_string($storedFont) && array_key_exists($storedFont, self::fontOptions())
            ? $storedFont
            : self::defaultFontKey();
    }

    /**
     * @return array{key: string, label: string, google_family: string, css_family: string, google_fonts_url: string}
     */
    public static function current(): array
    {
        $fontKey = self::currentFontKey();
        $font = self::fontOptions()[$fontKey];

        return [
            'key' => $fontKey,
            'label' => $font['label'],
            'google_family' => $font['google_family'],
            'css_family' => $font['css_family'],
            'google_fonts_url' => self::googleFontsUrl([$fontKey => $font]),
        ];
    }

    /**
     * @param  array<string, array{label: string, google_family: string, css_family: string}>  $fonts
     */
    public static function googleFontsUrl(array $fonts): string
    {
        $families = array_map(
            fn (array $font): string => 'family='.str_replace('%20', '+', rawurlencode($font['google_family'])).':wght@400;500;600;700',
            $fonts,
        );

        return 'https://fonts.googleapis.com/css2?'.implode('&', $families).'&display=swap';
    }

    private static function defaultFontKey(): string
    {
        $defaultFont = config('appearance.default_font', 'manrope');

        return array_key_exists($defaultFont, self::fontOptions()) ? $defaultFont : 'manrope';
    }
}
