<?php

namespace App\Http\Controllers;

use App\Support\ApplicationVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        $url = fn (string $path): string => rtrim($request->getSchemeAndHttpHost(), '/').'/'.ltrim($path, '/');

        return response()
            ->json([
                'name' => 'Ladna - Studio Management',
                'short_name' => 'Ladna',
                'description' => 'Ladna keeps schedules, bookings, class passes, trainers, rooms, and customer flows in order for sports studios.',
                'id' => $url('/'),
                'lang' => 'en',
                'dir' => 'ltr',
                'start_url' => $url('/login'),
                'scope' => $url('/'),
                'display' => 'standalone',
                'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
                'orientation' => 'any',
                'background_color' => '#FAF8F5',
                'theme_color' => '#3B223F',
                'categories' => ['business', 'productivity', 'health', 'sports'],
                'icons' => [
                    [
                        'src' => $url('/pwa/icon-192.png'),
                        'sizes' => '192x192',
                        'type' => 'image/png',
                        'purpose' => 'any',
                    ],
                    [
                        'src' => $url('/pwa/icon-512.png'),
                        'sizes' => '512x512',
                        'type' => 'image/png',
                        'purpose' => 'any',
                    ],
                    [
                        'src' => $url('/pwa/maskable-icon-192.png'),
                        'sizes' => '192x192',
                        'type' => 'image/png',
                        'purpose' => 'maskable',
                    ],
                    [
                        'src' => $url('/pwa/maskable-icon-512.png'),
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
                        'url' => $url('/dashboard'),
                        'icons' => [['src' => $url('/pwa/icon-192.png'), 'sizes' => '192x192']],
                    ],
                    [
                        'name' => 'Help',
                        'short_name' => 'Help',
                        'description' => 'Open Ladna owner help.',
                        'url' => $url('/help'),
                        'icons' => [['src' => $url('/pwa/icon-192.png'), 'sizes' => '192x192']],
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

    public function offline(): Response
    {
        return response()
            ->view('pwa.offline')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function serviceWorker(): Response
    {
        return response()
            ->view('pwa.service-worker')
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Service-Worker-Allowed', '/')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
