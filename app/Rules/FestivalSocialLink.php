<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final class FestivalSocialLink implements ValidationRule
{
    private function __construct(private readonly string $platform) {}

    public static function instagram(): self
    {
        return new self('instagram');
    }

    public static function telegram(): self
    {
        return new self('telegram');
    }

    public function help(): string
    {
        return __('app.festival_'.$this->platform.'_contact_help');
    }

    public function placeholder(): string
    {
        return __('app.festival_'.$this->platform.'_contact_placeholder');
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
            $fail(__('app.festival_'.$this->platform.'_contact_invalid'));

            return;
        }

        $reference = trim((string) $value);
        $handlePattern = $this->platform === 'instagram'
            ? '/^@[A-Za-z0-9._]{1,30}$/'
            : '/^@[A-Za-z0-9_]{1,32}$/';

        if (preg_match($handlePattern, $reference) === 1) {
            return;
        }

        if ($this->platform === 'telegram'
            && preg_match('/^(?:[A-Za-z0-9_]{1,32}|\d+)$/', $reference) === 1) {
            return;
        }

        $url = preg_match('/^(?:t\.me|telegram\.me)\//i', $reference) === 1
            ? 'https://'.$reference
            : $reference;
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = preg_replace('/^www\./i', '', mb_strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
        $allowedHosts = $this->platform === 'instagram' ? ['instagram.com'] : ['t.me', 'telegram.me'];
        $hostIsAllowed = collect($allowedHosts)->contains(
            fn (string $allowedHost): bool => $host === $allowedHost || str_ends_with($host, '.'.$allowedHost),
        );

        if (! in_array($scheme, ['http', 'https'], true)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! $hostIsAllowed
            || blank(trim((string) parse_url($url, PHP_URL_PATH), '/'))) {
            $fail(__('app.festival_'.$this->platform.'_contact_invalid'));
        }
    }
}
