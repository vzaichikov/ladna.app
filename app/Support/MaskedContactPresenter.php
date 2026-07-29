<?php

namespace App\Support;

use Illuminate\Support\Str;

class MaskedContactPresenter
{
    public function phone(?string $phone): ?string
    {
        $phone = filled($phone) ? trim((string) $phone) : null;

        if (! $phone) {
            return null;
        }

        $hiddenLength = max(0, Str::length($phone) - 4);

        return $hiddenLength > 0 ? Str::mask($phone, '•', 0, $hiddenLength) : $phone;
    }

    public function email(?string $email): ?string
    {
        $email = filled($email) ? trim((string) $email) : null;

        if (! $email || ! Str::contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::substr($local, 0, 1).'***@'.$domain;
    }
}
