@php
    $systemAppearance = $systemAppearance ?? \App\Support\SystemAppearance::current();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', __('app.app_name'))</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
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
        @yield('content')

        @unless ($isEmbed ?? false)
            @hasSection('publicFooter')
                @yield('publicFooter')
            @elseif (! ($hideAppFooter ?? false))
                <x-ui.app-footer :version="$applicationVersion" />
            @endif
        @endunless
    </body>
</html>
