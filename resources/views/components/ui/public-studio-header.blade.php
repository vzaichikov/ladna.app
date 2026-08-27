@props(['account'])

@php
    $studioColor = is_string($account->brand_color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $account->brand_color)
        ? $account->brand_color
        : '#3B223F';
@endphp

<header
    {{ $attributes->class('overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm') }}
    style="--studio-brand-color: {{ $studioColor }};"
    data-public-studio-header
>
    <div class="h-1.5" style="background-color: var(--studio-brand-color);" aria-hidden="true"></div>
    <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <a href="{{ route('public.studio', $account->slug) }}" class="flex min-w-0 items-center gap-3 text-slate-950 transition hover:text-brand-700">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-stone-200 bg-slate-50 p-2 shadow-xs">
                <img src="{{ $account->logoUrl() }}" alt="" class="max-h-8 max-w-8 object-contain">
            </span>
            <span class="min-w-0">
                <span class="block truncate text-lg font-semibold sm:text-xl">{{ $account->name }}</span>
                @if ($account->studio_slogan)
                    <span class="mt-0.5 block line-clamp-1 text-xs text-slate-500 sm:text-sm">{{ $account->studio_slogan }}</span>
                @endif
            </span>
        </a>

        <div class="flex flex-wrap items-center gap-2 self-start sm:self-auto">
            @isset($actions)
                {{ $actions }}
            @endisset

            <x-ui.button :href="route('public.studio', $account->slug)" variant="secondary" size="sm">
                <x-ui.icon name="external-link" class="h-4 w-4" />
                {{ __('app.studio_public_landing') }}
            </x-ui.button>
        </div>
    </div>
</header>
