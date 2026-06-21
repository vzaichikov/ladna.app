<?php

namespace App\Support;

use App\Models\CustomerClassPass;

class ClassPassCodeGenerator
{
    public function unique(): string
    {
        do {
            $code = $this->randomCode();
        } while (CustomerClassPass::where('code', $code)->exists());

        return $code;
    }

    private function randomCode(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $left = '';
        $right = '';

        for ($i = 0; $i < 4; $i++) {
            $left .= $characters[random_int(0, strlen($characters) - 1)];
            $right .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $left.'-'.$right;
    }
}
