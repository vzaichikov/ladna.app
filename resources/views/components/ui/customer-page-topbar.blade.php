@props([
    'homeUrl' => route('home'),
])

<header
    {{ $attributes->class('flex items-center justify-between gap-4') }}
    data-customer-page-topbar
>
    <a href="{{ $homeUrl }}" class="inline-flex items-center gap-3 text-slate-950 transition hover:text-brand-700">
        <x-ui.app-logo mark-class="h-9 w-9 sm:h-10 sm:w-10" text-class="text-current" />
    </a>

    <div class="flex min-w-0 items-center justify-end gap-2">
        @if ($slot->hasActualContent())
            <div class="min-w-0">
                {{ $slot }}
            </div>
        @endif

        <x-ui.customer-locale-switcher />
    </div>
</header>
