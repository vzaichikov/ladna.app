@props([
    'version' => '0.0.0',
])

@php
    $localizedSuffix = app()->getLocale() === 'uk' ? 'ua' : 'en';
    $changelogRoute = "changelog.$localizedSuffix";
    $privacyRoute = "privacy.$localizedSuffix";
    $termsRoute = "terms.$localizedSuffix";
@endphp

<footer {{ $attributes->merge(['class' => 'border-t border-stone-200/80 bg-canvas/85 px-4 py-5 text-sm text-slate-500 sm:px-6 lg:px-8']) }}>
    <div class="mx-auto flex max-w-7xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p>
            &copy; {{ now()->year }} {{ __('app.app_name') }}.
            <span class="whitespace-nowrap">{{ __('app.version') }} {{ $version }}</span>
        </p>
        <nav class="flex flex-wrap gap-x-4 gap-y-2 font-semibold" aria-label="{{ __('app.footer_links') }}">
            <a href="{{ route($termsRoute) }}" class="text-brand-600 transition hover:text-brand-700">
                {{ __('app.terms_of_service') }}
            </a>
            <a href="{{ route($privacyRoute) }}" class="text-brand-600 transition hover:text-brand-700">
                {{ __('app.privacy_policy') }}
            </a>
            <a href="{{ route($changelogRoute) }}" class="text-brand-600 transition hover:text-brand-700">
                {{ __('app.whats_new') }}
            </a>
            <a href="{{ route('help.index') }}" class="text-brand-600 transition hover:text-brand-700">
                {{ __('app.help') }}
            </a>
            <a href="{{ route('api-docs.show') }}" class="text-brand-600 transition hover:text-brand-700">
                {{ __('app.api_documentation') }}
            </a>
        </nav>
    </div>
</footer>
