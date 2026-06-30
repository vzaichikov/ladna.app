@props([
    'image',
    'status',
])

@php
    $status = (string) $status;
    $locales = array_keys(config('ladna.locales', ['en' => 'English']));
    $sessionLocale = null;

    try {
        if (request()->hasSession()) {
            $sessionLocale = request()->session()->get('locale');
        }
    } catch (\Throwable) {
        $sessionLocale = null;
    }

    $errorLocale = is_string($sessionLocale) && in_array($sessionLocale, $locales, true)
        ? $sessionLocale
        : 'en';

    $homeHref = route($errorLocale === 'uk' ? 'home' : 'home.en');
    $loginHref = route($errorLocale === 'uk' ? 'login' : 'login.en');
    $copy = trans("app.error_pages.{$status}", [], $errorLocale);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $errorLocale) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $status }} · {{ __('app.app_name', [], $errorLocale) }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <meta name="theme-color" content="#3B223F">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap">
        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-brand-50 antialiased" style="--app-font-family: 'Manrope';">
        <main class="relative min-h-screen overflow-hidden bg-[#FAF8F5] px-5 py-5 text-[#2B2B2F] sm:px-8 lg:px-10">
            <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="absolute inset-x-0 top-24 h-px bg-gradient-to-r from-transparent via-[#A78AB9]/28 to-transparent"></div>
                <div class="absolute left-5 top-32 grid grid-cols-4 gap-2 opacity-55 sm:left-12 lg:left-20">
                    @foreach ([true, false, false, true, false, true, false, false, true, false, true, false] as $isActive)
                        <span class="h-6 w-9 rounded-md {{ $isActive ? 'bg-[#C7B4D3]/45' : 'bg-white/75' }}"></span>
                    @endforeach
                </div>
                <div class="absolute bottom-16 right-6 grid grid-cols-3 gap-2 opacity-45 sm:right-14 lg:right-24">
                    @foreach ([false, true, false, true, false, true, false, false, true] as $isActive)
                        <span class="h-4 w-12 rounded-md {{ $isActive ? 'bg-[#A78AB9]/38' : 'bg-[#E7DDC9]/55' }}"></span>
                    @endforeach
                </div>
                <svg class="absolute right-4 top-20 hidden h-56 w-56 text-[#A78AB9]/28 sm:right-12 md:block lg:right-20 lg:h-80 lg:w-80" viewBox="0 0 320 320" fill="none">
                    <path d="M36 218C88 76 214 48 284 101" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                    <path d="M80 265C126 223 207 214 258 246" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    <circle cx="284" cy="101" r="6" fill="currentColor" />
                </svg>
            </div>

            <div class="relative mx-auto flex min-h-[calc(100vh-2.5rem)] max-w-7xl flex-col">
                <header class="flex items-center justify-between gap-4">
                    <a href="{{ $homeHref }}" class="inline-flex items-center gap-3 text-[#2B1731]">
                        <x-ui.app-logo
                            mark-class="h-10 w-10"
                            text-class="text-[#2B1731]"
                        />
                    </a>

                    <span class="inline-flex h-10 items-center justify-center rounded-lg border border-[#A78AB9]/30 bg-white/72 px-3 text-xs font-semibold uppercase text-[#4D3152] shadow-xs">
                        {{ trans('app.error_pages.status', ['status' => $status], $errorLocale) }}
                    </span>
                </header>

                <section class="grid flex-1 items-center gap-8 py-10 lg:grid-cols-[0.9fr_1.1fr] lg:py-6">
                    <div class="max-w-2xl">
                        <p class="text-sm font-semibold uppercase text-[#A78AB9]">
                            {{ $copy['eyebrow'] }}
                        </p>
                        <h1 class="mt-4 text-4xl font-semibold leading-tight text-[#2B1731] sm:text-5xl lg:text-6xl">
                            {{ $copy['title'] }}
                        </h1>
                        <p class="mt-6 text-base leading-8 text-[#4D3152]/80 sm:text-lg">
                            {{ $copy['lead'] }}
                        </p>
                        <p class="mt-6 border-l-2 border-[#A78AB9]/40 pl-4 text-sm leading-6 text-[#4D3152]/70">
                            {{ $copy['hint'] }}
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <x-ui.button :href="$homeHref" size="lg">
                                {{ trans('app.error_pages.home', [], $errorLocale) }}
                            </x-ui.button>
                            <x-ui.button :href="$loginHref" variant="secondary" size="lg">
                                {{ trans('app.login', [], $errorLocale) }}
                            </x-ui.button>
                        </div>
                    </div>

                    <div class="relative flex items-center justify-center lg:min-h-[39rem]">
                        <div class="absolute bottom-8 left-1/2 h-24 w-[70%] -translate-x-1/2 rounded-[50%] bg-[#3B223F]/9 blur-2xl"></div>
                        <img
                            src="{{ asset($image) }}"
                            alt="{{ $copy['image_alt'] }}"
                            class="relative h-auto max-h-[34rem] w-auto max-w-full object-contain drop-shadow-[0_28px_54px_rgba(59,34,63,0.16)] sm:max-h-[70vh] lg:max-h-[78vh] lg:max-w-[40rem]"
                        >
                    </div>
                </section>
            </div>
        </main>
    </body>
</html>
