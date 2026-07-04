<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RtspUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('app.rtsp_url_invalid'));

            return;
        }

        $parts = parse_url(trim($value));
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? '')) : '';
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;

        if (! in_array($scheme, ['rtsp', 'rtsps'], true) || $host === '') {
            $fail(__('app.rtsp_url_invalid'));

            return;
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            $fail(__('app.rtsp_url_invalid'));
        }
    }
}
