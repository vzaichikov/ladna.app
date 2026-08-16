<?php

namespace App\Support\Entrance;

class EntrancePresenter
{
    public function email(?string $email): ?string
    {
        if (blank($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $email, 2);
        $visible = mb_substr($name, 0, min(2, mb_strlen($name)));

        return $visible.str_repeat('•', max(2, min(6, mb_strlen($name) - mb_strlen($visible)))).'@'.$domain;
    }

    public function phone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if (mb_strlen($digits) < 4) {
            return str_repeat('•', mb_strlen($digits));
        }

        return '+'.str_repeat('•', max(4, mb_strlen($digits) - 4)).mb_substr($digits, -4);
    }
}
