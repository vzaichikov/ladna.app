@props([
    'applicationVersion' => null,
])

@php
    $localizedSuffix = app()->getLocale() === 'uk' ? 'ua' : 'en';
    $applicationVersion ??= \App\Support\ApplicationVersion::current();
@endphp

<footer {{ $attributes->class(['border-t border-[#E7DDC9]/80 bg-[#FAF8F5] px-5 py-6 text-sm text-[#4D3152]/70 sm:px-8 lg:px-10']) }}>
    <div class="mx-auto flex max-w-7xl flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p>
            &copy; {{ now()->year }} {{ __('app.app_name') }}. {{ __('app.app_tagline') }}.
            <span class="whitespace-nowrap">{{ __('app.version') }} {{ $applicationVersion }}</span>
        </p>
        <nav class="flex flex-wrap gap-x-5 gap-y-2 font-semibold" aria-label="{{ __('app.footer_links') }}">
            <a href="{{ route('terms.'.$localizedSuffix) }}" class="text-[#3B223F] transition hover:text-[#2B1731]">
                {{ __('app.terms_of_service') }}
            </a>
            <a href="{{ route('privacy.'.$localizedSuffix) }}" class="text-[#3B223F] transition hover:text-[#2B1731]">
                {{ __('app.privacy_policy') }}
            </a>
            <a href="{{ route('changelog.'.$localizedSuffix) }}" class="text-[#3B223F] transition hover:text-[#2B1731]">
                {{ __('app.whats_new') }}
            </a>
            <a href="{{ route('help.index') }}" class="text-[#3B223F] transition hover:text-[#2B1731]">
                {{ __('app.help') }}
            </a>
        </nav>
    </div>
</footer>
