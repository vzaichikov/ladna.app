<?php

namespace App\Support;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class IntegrationCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function categories(): array
    {
        return config('integrations.categories', []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function providers(): array
    {
        return config('integrations.providers', []);
    }

    /**
     * @return array<int, string>
     */
    public static function providerKeys(): array
    {
        return array_keys(self::providers());
    }

    /**
     * @return array<string, mixed>
     */
    public static function provider(string $provider): array
    {
        $definition = self::providers()[$provider] ?? null;

        abort_unless(is_array($definition), 404);

        return $definition;
    }

    public static function providerCategory(string $provider): IntegrationCategory
    {
        return IntegrationCategory::from(self::provider($provider)['category']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function providersForCategory(string|IntegrationCategory $category, ?IntegrationScope $scope = null): array
    {
        $categoryValue = $category instanceof IntegrationCategory ? $category->value : $category;

        return Arr::where(self::providers(), function (array $provider) use ($categoryValue, $scope): bool {
            if ($provider['category'] !== $categoryValue) {
                return false;
            }

            if (! $scope) {
                return true;
            }

            return in_array($scope->value, $provider['scopes'] ?? [IntegrationScope::Platform->value, IntegrationScope::Account->value], true);
        });
    }

    public static function activeCategory(mixed $category): IntegrationCategory
    {
        if (is_string($category) && array_key_exists($category, self::categories())) {
            return IntegrationCategory::from($category);
        }

        return IntegrationCategory::Payment;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public static function displayCredentials(string $provider, array $credentials): array
    {
        return array_replace(self::defaults($provider), $credentials);
    }

    /**
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public static function credentialsForStorage(string $provider, array $submitted, array $existing = []): array
    {
        $credentials = [];

        foreach (self::provider($provider)['fields'] as $field => $definition) {
            $value = $submitted[$field] ?? null;

            if (($definition['sensitive'] ?? false) && self::blank($value)) {
                $value = $existing[$field] ?? null;
            }

            if (self::blank($value) && array_key_exists('default', $definition)) {
                $value = $definition['default'];
            }

            if (self::blank($value)) {
                continue;
            }

            $credentials[$field] = self::castValue($value, $definition);
        }

        return $credentials;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rulesFor(string $provider): array
    {
        $rules = [
            'is_enabled' => ['nullable', 'boolean'],
            'credentials' => ['nullable', 'array'],
        ];

        foreach (self::provider($provider)['fields'] as $field => $definition) {
            $fieldRules = ['nullable'];

            if (($definition['type'] ?? 'text') === 'integer') {
                $fieldRules[] = 'integer';

                if (array_key_exists('min', $definition)) {
                    $fieldRules[] = 'min:'.$definition['min'];
                }

                if (array_key_exists('max', $definition)) {
                    $fieldRules[] = 'max:'.$definition['max'];
                }
            } elseif (($definition['type'] ?? 'text') === 'email') {
                $fieldRules[] = 'email';
                $fieldRules[] = 'max:'.($definition['max'] ?? 255);
            } elseif (($definition['type'] ?? 'text') === 'textarea') {
                $fieldRules[] = 'string';
                $fieldRules[] = 'max:'.($definition['max'] ?? 8192);
            } elseif (($definition['type'] ?? 'text') === 'select') {
                $fieldRules[] = Rule::in(array_keys($definition['options'] ?? []));
            } else {
                $fieldRules[] = 'string';
                $fieldRules[] = 'max:'.($definition['max'] ?? 255);
            }

            $rules['credentials.'.$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $existing
     * @return array<string, string>
     */
    public static function missingRequiredFields(string $provider, array $submitted, array $existing = []): array
    {
        $missing = [];
        $merged = array_replace(self::defaults($provider), $existing, $submitted);

        foreach (self::provider($provider)['fields'] as $field => $definition) {
            if (! self::fieldIsRequired($definition, $merged)) {
                continue;
            }

            $value = $submitted[$field] ?? null;

            if (($definition['sensitive'] ?? false) && self::blank($value)) {
                $value = $existing[$field] ?? null;
            }

            if (self::blank($value) && array_key_exists('default', $definition)) {
                $value = $definition['default'];
            }

            if (self::blank($value)) {
                $missing[$field] = $definition['label_key'];
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function hasRequiredCredentials(string $provider, array $credentials): bool
    {
        return self::missingRequiredFields($provider, $credentials, $credentials) === [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(string $provider): array
    {
        $defaults = [];

        foreach (self::provider($provider)['fields'] as $field => $definition) {
            if (array_key_exists('default', $definition)) {
                $defaults[$field] = $definition['default'];
            }
        }

        return $defaults;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $credentials
     */
    private static function fieldIsRequired(array $definition, array $credentials): bool
    {
        if ($definition['required_when_enabled'] ?? false) {
            return true;
        }

        foreach (($definition['required_when_enabled_if'] ?? []) as $field => $value) {
            if (($credentials[$field] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function castValue(mixed $value, array $definition): mixed
    {
        if (($definition['type'] ?? 'text') === 'integer') {
            return (int) $value;
        }

        return is_string($value) ? trim($value) : $value;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
