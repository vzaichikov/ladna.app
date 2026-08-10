<?php

namespace App\Rules;

use App\Support\ReservedPublicSlugs;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class NotReservedPublicSlug implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! ReservedPublicSlugs::isReserved($value)) {
            return;
        }

        $fail('validation.reserved_public_slug')->translate();
    }
}
