<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class LocalizationCatalogTest extends TestCase
{
    /** @var list<string> */
    private const CATALOGS = ['app', 'features', 'founders', 'pagination', 'validation'];

    /** @var list<string> */
    private const LOCALES = ['en', 'uk'];

    public function test_supported_locales_have_only_the_same_php_catalogs(): void
    {
        $langPath = $this->projectPath('lang');

        $this->assertSame([], glob($langPath.'/*.json') ?: []);

        foreach (self::LOCALES as $locale) {
            $files = array_map(
                static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
                glob($langPath."/{$locale}/*.php") ?: [],
            );
            sort($files);

            $expectedCatalogs = self::CATALOGS;
            sort($expectedCatalogs);

            $this->assertSame($expectedCatalogs, $files, "Unexpected catalog set for [{$locale}].");
        }
    }

    public function test_catalog_structure_and_placeholders_match_between_locales(): void
    {
        foreach (self::CATALOGS as $catalog) {
            $english = $this->catalog('en', $catalog);
            $ukrainian = $this->catalog('uk', $catalog);

            $this->assertSame(
                $this->structure($english),
                $this->structure($ukrainian),
                "Catalog structure differs for [{$catalog}].",
            );

            $englishStrings = $this->strings($english);
            $ukrainianStrings = $this->strings($ukrainian);

            foreach ($englishStrings as $key => $value) {
                $this->assertSame(
                    $this->placeholders($value),
                    $this->placeholders($ukrainianStrings[$key]),
                    "Translation placeholders differ for [{$catalog}.{$key}].",
                );
            }
        }
    }

    public function test_validation_catalogs_cover_every_installed_framework_rule(): void
    {
        $frameworkValidation = require $this->projectPath('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        unset($frameworkValidation['custom'], $frameworkValidation['attributes']);

        $frameworkStructure = $this->structure($frameworkValidation);

        foreach (self::LOCALES as $locale) {
            $missingKeys = array_diff_key($frameworkStructure, $this->structure($this->catalog($locale, 'validation')));

            $this->assertSame([], $missingKeys, "Framework validation keys are missing for [{$locale}].");
        }
    }

    public function test_literal_application_translation_keys_exist_in_both_locales(): void
    {
        $literalKeys = $this->literalApplicationTranslationKeys();

        foreach (self::LOCALES as $locale) {
            $catalog = $this->catalog($locale, 'app');

            foreach ($literalKeys as $key) {
                $this->assertTrue(Arr::has($catalog, $key), "Missing translation [app.{$key}] for [{$locale}].");
            }
        }
    }

    /** @return array<string, mixed> */
    private function catalog(string $locale, string $catalog): array
    {
        return require $this->projectPath("lang/{$locale}/{$catalog}.php");
    }

    /** @return array<string, string> */
    private function structure(array $values, string $prefix = ''): array
    {
        $structure = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $structure[$path] = get_debug_type($value);

            if (is_array($value)) {
                $structure += $this->structure($value, $path);
            }
        }

        ksort($structure);

        return $structure;
    }

    /** @return array<string, string> */
    private function strings(array $values, string $prefix = ''): array
    {
        $strings = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $strings += $this->strings($value, $path);

                continue;
            }

            $strings[$path] = $value;
        }

        ksort($strings);

        return $strings;
    }

    /** @return list<string> */
    private function placeholders(string $value): array
    {
        preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $value, $matches);
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }

    /** @return list<string> */
    private function literalApplicationTranslationKeys(): array
    {
        $keys = [];

        foreach (['app', 'resources/views', 'routes'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->projectPath($directory)));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                preg_match_all(
                    '/(?:__|trans|trans_choice)\(\s*[\'\"]app\.([A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)[\'\"](?=\s*[,)])/',
                    $contents,
                    $matches,
                );
                array_push($keys, ...$matches[1]);
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
