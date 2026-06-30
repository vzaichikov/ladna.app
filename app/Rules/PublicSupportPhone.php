<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PublicSupportPhone implements ValidationRule
{
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
            $fail(__('app.public_support_phone_invalid'));

            return;
        }

        $phone = trim((string) $value);

        if ($phone === '' || preg_match('/[\x00-\x1F\x7F]/', $phone) === 1) {
            $fail(__('app.public_support_phone_invalid'));

            return;
        }

        if (preg_match('/^\+?[0-9][0-9\s().-]{4,63}$/', $phone) !== 1) {
            $fail(__('app.public_support_phone_invalid'));
        }
    }
}
