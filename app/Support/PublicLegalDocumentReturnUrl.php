<?php

namespace App\Support;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicLegalDocumentReturnUrl
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_NAMES = [
        'public.studio',
        'public.schedule',
        'public.schedule.embed',
        'public.price',
        'public.price.embed',
        'public.class-pass-plans.buy',
    ];

    public function resolve(Request $request, Account $account): string
    {
        $fallback = route('public.studio', $account->slug, absolute: false);
        $returnTo = $request->query('return_to');

        if (! is_string($returnTo) || $returnTo === '' || strlen($returnTo) > 2048) {
            return $fallback;
        }

        $parts = parse_url($returnTo);

        if (! is_array($parts) || ! $this->hasTrustedOrigin($parts, $request)) {
            return $fallback;
        }

        $path = $parts['path'] ?? null;

        if (! is_string($path) || ! str_starts_with($path, '/')) {
            return $fallback;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return $fallback;
        }

        if (! in_array($route->getName(), self::ALLOWED_ROUTE_NAMES, true)
            || $route->parameter('accountSlug') !== $account->slug) {
            return $fallback;
        }

        $returnPath = route($route->getName(), $route->parameters(), absolute: false);
        $query = $parts['query'] ?? null;

        return is_string($query) && $query !== '' ? $returnPath.'?'.$query : $returnPath;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function hasTrustedOrigin(array $parts, Request $request): bool
    {
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['scheme']) && strtolower((string) $parts['scheme']) !== strtolower($request->getScheme())) {
            return false;
        }

        if (isset($parts['host']) && strtolower((string) $parts['host']) !== strtolower($request->getHost())) {
            return false;
        }

        return ! isset($parts['port']) || (int) $parts['port'] === $request->getPort();
    }
}
