<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PublicSupportLink implements ValidationRule
{
    /**
     * @param  array<int, string>  $allowedProtocols
     */
    public function __construct(private readonly array $allowedProtocols = ['http', 'https']) {}

    public static function instagram(): self
    {
        return new self(['http', 'https']);
    }

    public static function telegram(): self
    {
        return new self(['http', 'https', 'tg', 'telegram']);
    }

    public static function viber(): self
    {
        return new self(['http', 'https', 'viber']);
    }

    public static function whatsapp(): self
    {
        return new self(['http', 'https', 'whatsapp']);
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_scalar($value)) {
            $fail(__('app.public_support_link_invalid'));

            return;
        }

        $url = trim((string) $value);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            $fail(__('app.public_support_link_invalid'));

            return;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), $this->allowedProtocols, true)) {
            $fail(__('app.public_support_link_invalid'));

            return;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $fail(__('app.public_support_link_invalid'));
        }
    }
}
