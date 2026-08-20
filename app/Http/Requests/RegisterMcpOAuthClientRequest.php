<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterMcpOAuthClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_name' => ['nullable', 'string', 'min:1', 'max:255', 'required_without:name'],
            'name' => ['nullable', 'string', 'min:1', 'max:255', 'required_without:client_name'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:10'],
            'redirect_uris.*' => [
                'required',
                'string',
                'max:2048',
                'distinct',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! $this->isAllowedRedirectUri($value)) {
                        $fail(__('app.mcp_oauth_redirect_not_allowed'));
                    }
                },
            ],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $redirectUriError = collect($validator->errors()->keys())
            ->contains(fn (string $key): bool => $key === 'redirect_uris' || str_starts_with($key, 'redirect_uris.'));

        throw new HttpResponseException(response()->json([
            'error' => $redirectUriError ? 'invalid_redirect_uri' : 'invalid_client_metadata',
            'error_description' => $validator->errors()->first(),
        ], 400));
    }

    private function isAllowedRedirectUri(string $uri): bool
    {
        if (filter_var($uri, FILTER_VALIDATE_URL) === false || parse_url($uri, PHP_URL_FRAGMENT) !== null) {
            return false;
        }

        $scheme = parse_url($uri, PHP_URL_SCHEME);
        $host = parse_url($uri, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host)) {
            return false;
        }

        if ($scheme === 'http' && $this->isNativeLoopbackRedirectUri($uri, $host)) {
            return true;
        }

        if (! app()->isProduction() && $scheme === 'http' && strtolower($host) === 'localhost') {
            return true;
        }

        $origin = strtolower($scheme).'://'.strtolower($host);
        $port = parse_url($uri, PHP_URL_PORT);

        if (is_int($port)) {
            $origin .= ':'.$port;
        }

        return collect(config('mcp.redirect_domains', []))
            ->contains(fn (string $allowedOrigin): bool => hash_equals(rtrim(strtolower($allowedOrigin), '/'), $origin));
    }

    private function isNativeLoopbackRedirectUri(string $uri, string $host): bool
    {
        if (! in_array(strtolower($host), ['127.0.0.1', '[::1]'], true)) {
            return false;
        }

        return is_int(parse_url($uri, PHP_URL_PORT));
    }
}
