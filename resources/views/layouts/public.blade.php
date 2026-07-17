@php
    $systemAppearance = $systemAppearance ?? \App\Support\SystemAppearance::current();
    $isEmbedLayout = $isEmbed ?? false;
    $candidateAccount = $account ?? null;
    $publicPwaAccount = ! $isEmbedLayout && $candidateAccount instanceof \App\Models\Account && $candidateAccount->exists
        ? $candidateAccount
        : null;
    $isReadOnlyDemo = $publicPwaAccount?->isReadOnlyDemo() ?? false;
    $isCentralAppScope = request()->is('app') || request()->is('app/*');
    $pwaManifestUrl = null;
    $pwaAppleTouchIconUrl = null;
    $pwaThemeColor = '#3B223F';
    $pwaTitle = __('app.app_name');
    $pwaVersionUrl = null;
    $pwaServiceWorkerUrl = null;

    if ($publicPwaAccount) {
        $pwaManifestUrl = route('pwa.studio.manifest', $publicPwaAccount->slug);
        $pwaAppleTouchIconUrl = route('pwa.studio.icon', [$publicPwaAccount->slug, 180]);
        $pwaThemeColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $publicPwaAccount->brand_color) === 1
            ? $publicPwaAccount->brand_color
            : '#3B223F';
        $pwaTitle = $publicPwaAccount->name;
        $pwaVersionUrl = route('pwa.studio.version', $publicPwaAccount->slug);
        $pwaServiceWorkerUrl = route('pwa.studio.service-worker', $publicPwaAccount->slug);
    } elseif (! $isEmbedLayout && $isCentralAppScope) {
        $pwaManifestUrl = route('pwa.manifest');
        $pwaAppleTouchIconUrl = asset('pwa/apple-touch-icon.png');
        $pwaVersionUrl = route('pwa.version');
        $pwaServiceWorkerUrl = route('pwa.service-worker');
    }
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', __('app.app_name'))</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        @if ($pwaManifestUrl)
            <link rel="manifest" href="{{ $pwaManifestUrl }}">
            <link rel="apple-touch-icon" href="{{ $pwaAppleTouchIconUrl }}">
            <meta name="theme-color" content="{{ $pwaThemeColor }}">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-title" content="{{ $pwaTitle }}">
        @endif
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $systemAppearance['google_fonts_url'] }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('head')
    </head>
    <body
        class="antialiased"
        data-phone-mask-error="{{ __('app.phone_mask_error') }}"
        data-phone-mask-no-results="{{ __('app.phone_mask_no_results') }}"
        data-phone-mask-search="{{ __('app.phone_mask_search') }}"
        data-phone-mask-success="{{ __('app.phone_mask_success') }}"
        style="--app-font-family: '{{ $systemAppearance['css_family'] }}';"
    >
        @if ($pwaServiceWorkerUrl)
            <x-ui.pwa-install-button />
        @endif

        @if ($isReadOnlyDemo || (isset($errors) && $errors->has('demo')))
            <x-ui.demo-readonly-banner />
        @endif

        @yield('content')

        @unless ($isEmbedLayout)
            @hasSection('publicFooter')
                @yield('publicFooter')
            @elseif (! ($hideAppFooter ?? false))
                <x-ui.app-footer :version="$applicationVersion" />
            @endif
        @endunless

        @if ($pwaServiceWorkerUrl)
            <x-ui.update-reload-toast :revision="$applicationRevision" :version-url="$pwaVersionUrl" :service-worker-url="$pwaServiceWorkerUrl" />
        @endif
    </body>
</html>
