<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\ApplicationVersion;
use App\Support\Pwa\StudioPwaIconGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PwaController extends Controller
{
    public function version(): JsonResponse
    {
        return response()
            ->json([
                'version' => ApplicationVersion::current(),
                'revision' => ApplicationVersion::revision(),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function manifest(Request $request): JsonResponse
    {
        $url = fn (string $path): string => $this->absoluteUrl($request, $path);

        return response()
            ->json([
                'name' => 'Ladna - Studio Management',
                'short_name' => 'Ladna',
                'description' => 'Ladna keeps schedules, bookings, class passes, trainers, rooms, and customer flows in order for sports studios.',
                'id' => $url('/'),
                'lang' => 'en',
                'dir' => 'ltr',
                'start_url' => $url('/app/'),
                'scope' => $url('/app/'),
                'display' => 'standalone',
                'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
                'orientation' => 'any',
                'background_color' => '#FAF8F5',
                'theme_color' => '#3B223F',
                'categories' => ['business', 'productivity', 'health', 'sports'],
                'icons' => [
                    [
                        'src' => $url('/pwa/manifest-icon-192.png'),
                        'sizes' => '192x192',
                        'type' => 'image/png',
                        'purpose' => 'any',
                    ],
                    [
                        'src' => $url('/pwa/manifest-icon-512.png'),
                        'sizes' => '512x512',
                        'type' => 'image/png',
                        'purpose' => 'any',
                    ],
                    [
                        'src' => $url('/pwa/manifest-maskable-icon-192.png'),
                        'sizes' => '192x192',
                        'type' => 'image/png',
                        'purpose' => 'maskable',
                    ],
                    [
                        'src' => $url('/pwa/manifest-maskable-icon-512.png'),
                        'sizes' => '512x512',
                        'type' => 'image/png',
                        'purpose' => 'maskable',
                    ],
                ],
                'shortcuts' => [
                    [
                        'name' => 'Dashboard',
                        'short_name' => 'Dashboard',
                        'description' => 'Open the studio workspace.',
                        'url' => $url('/app/dashboard'),
                        'icons' => [['src' => $url('/pwa/manifest-icon-192.png'), 'sizes' => '192x192']],
                    ],
                    [
                        'name' => 'Help',
                        'short_name' => 'Help',
                        'description' => 'Open Ladna owner help.',
                        'url' => $url('/app/help'),
                        'icons' => [['src' => $url('/pwa/manifest-icon-192.png'), 'sizes' => '192x192']],
                    ],
                ],
                'screenshots' => [
                    [
                        'src' => $url('/pwa/screenshot-wide-dashboard.png'),
                        'sizes' => '1920x1080',
                        'type' => 'image/png',
                        'form_factor' => 'wide',
                        'label' => 'Studio dashboard, schedule, and daily operations in Ladna.',
                    ],
                    [
                        'src' => $url('/pwa/screenshot-wide-schedule.png'),
                        'sizes' => '1920x1080',
                        'type' => 'image/png',
                        'form_factor' => 'wide',
                        'label' => 'Public schedules, bookings, and class passes for studio teams.',
                    ],
                    [
                        'src' => $url('/pwa/screenshot-narrow-bookings.png'),
                        'sizes' => '1080x1920',
                        'type' => 'image/png',
                        'form_factor' => 'narrow',
                        'label' => 'Mobile-friendly studio bookings and class-pass control.',
                    ],
                    [
                        'src' => $url('/pwa/screenshot-narrow-clients.png'),
                        'sizes' => '1080x1920',
                        'type' => 'image/png',
                        'form_factor' => 'narrow',
                        'label' => 'Client portal, public prices, and studio links ready for mobile.',
                    ],
                ],
            ])
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function studioManifest(Request $request, string $accountSlug): JsonResponse
    {
        $account = $this->activeAccount($accountSlug);
        $url = fn (string $path): string => $this->absoluteUrl($request, $path);
        $themeColor = $this->themeColor($account);
        $iconVersion = $account->updated_at?->timestamp ?? $account->getKey();
        $iconUrl = fn (int $size): string => $url('/'.$account->slug.'/pwa/icon-'.$size.'.png?v='.$iconVersion);

        return response()
            ->json([
                'name' => $account->name,
                'short_name' => $account->name,
                'description' => $account->studio_slogan ?: 'Schedule, prices, booking, and customer portal for '.$account->name.'.',
                'id' => $url('/'.$account->slug.'/'),
                'lang' => $account->default_language,
                'dir' => 'ltr',
                'start_url' => $url('/'.$account->slug.'/'),
                'scope' => $url('/'.$account->slug.'/'),
                'display' => 'standalone',
                'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
                'orientation' => 'any',
                'background_color' => '#FAF8F5',
                'theme_color' => $themeColor,
                'categories' => ['health', 'sports', 'lifestyle'],
                'icons' => [
                    [
                        'src' => $iconUrl(192),
                        'sizes' => '192x192',
                        'type' => 'image/png',
                        'purpose' => 'any',
                    ],
                    [
                        'src' => $iconUrl(512),
                        'sizes' => '512x512',
                        'type' => 'image/png',
                        'purpose' => 'any',
                    ],
                    [
                        'src' => $iconUrl(192),
                        'sizes' => '192x192',
                        'type' => 'image/png',
                        'purpose' => 'maskable',
                    ],
                    [
                        'src' => $iconUrl(512),
                        'sizes' => '512x512',
                        'type' => 'image/png',
                        'purpose' => 'maskable',
                    ],
                ],
                'shortcuts' => [
                    [
                        'name' => 'Customer portal',
                        'short_name' => 'Portal',
                        'description' => 'Open the customer portal.',
                        'url' => $url('/'.$account->slug.'/customer/login'),
                        'icons' => [['src' => $iconUrl(192), 'sizes' => '192x192']],
                    ],
                ],
            ])
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function offline(): Response
    {
        return response()
            ->view('pwa.offline')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function studioOffline(string $accountSlug): Response
    {
        $this->activeAccount($accountSlug);

        return $this->offline();
    }

    public function serviceWorker(): Response
    {
        return response()
            ->view('pwa.service-worker')
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Service-Worker-Allowed', '/app/')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function studioServiceWorker(string $accountSlug): Response
    {
        $account = $this->activeAccount($accountSlug);

        return response()
            ->view('pwa.service-worker')
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Service-Worker-Allowed', '/'.$account->slug.'/')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function retiringServiceWorker(): Response
    {
        return response()
            ->view('pwa.retire-service-worker')
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Service-Worker-Allowed', '/')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function studioIcon(string $accountSlug, int $size, StudioPwaIconGenerator $icons): BinaryFileResponse
    {
        $account = $this->activeAccount($accountSlug);
        $response = response()->file($icons->path($account, $size));

        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Cache-Control', 'public, max-age=604800, immutable');

        return $response;
    }

    private function activeAccount(string $accountSlug): Account
    {
        return Account::active()
            ->where('slug', $accountSlug)
            ->firstOrFail();
    }

    private function absoluteUrl(Request $request, string $path): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/').'/'.ltrim($path, '/');
    }

    private function themeColor(Account $account): string
    {
        $color = (string) $account->brand_color;

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#3B223F';
    }
}
