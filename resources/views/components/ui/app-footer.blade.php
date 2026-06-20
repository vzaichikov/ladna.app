@props([
    'version' => '0.0.0',
])

@php
    $changelogRoute = app()->getLocale() === 'uk' ? 'changelog.ua' : 'changelog.en';
@endphp

<footer {{ $attributes->merge(['class' => 'border-t border-stone-200/80 bg-canvas/85 px-4 py-5 text-sm text-slate-500 sm:px-6 lg:px-8']) }}>
    <div class="mx-auto flex max-w-7xl flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p>
            &copy; {{ now()->year }} {{ __('app.app_name') }}.
            <span class="whitespace-nowrap">{{ __('app.version') }} {{ $version }}</span>
        </p>
        <a href="{{ route($changelogRoute) }}" class="font-semibold text-brand-600 transition hover:text-brand-700">
            {{ __('app.whats_new') }}
        </a>
    </div>
</footer>
