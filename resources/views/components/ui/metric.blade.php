@props([
    'label',
    'value',
    'meta' => null,
    'icon' => 'sparkles',
    'accent' => 'violet',
    'href' => null,
    'mobileInline' => false,
])

@php
    $accents = [
        'violet' => 'bg-violet-crm-100 text-brand-700',
        'brand' => 'bg-brand-100 text-brand-700',
        'emerald' => 'bg-emerald-50 text-emerald-700',
        'amber' => 'bg-amber-50 text-amber-700',
        'slate' => 'bg-slate-100 text-slate-700',
    ];
    $wrapper = 'group block rounded-xl border border-stone-200 bg-white shadow-crm transition hover:-translate-y-0.5 hover:border-violet-crm-100 hover:shadow-lg '.($mobileInline ? 'p-3 sm:p-4' : 'p-4');
@endphp

@php ob_start(); @endphp
    @if ($mobileInline)
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg sm:h-10 sm:w-10 {{ $accents[$accent] ?? $accents['violet'] }}">
                    <x-ui.icon :name="$icon" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <div class="text-sm font-medium leading-snug text-slate-500">{{ $label }}</div>
                    @if ($meta)
                        <div class="mt-0.5 text-xs font-medium text-slate-500">{{ $meta }}</div>
                    @endif
                </div>
            </div>
            <div class="shrink-0 text-2xl font-semibold text-slate-950">{{ $value }}</div>
            @if ($href)
                <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-brand-500" />
            @endif
        </div>
    @else
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg {{ $accents[$accent] ?? $accents['violet'] }}">
                    <x-ui.icon :name="$icon" class="h-5 w-5" />
                </span>
                <div>
                    <div class="text-sm font-medium text-slate-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold text-slate-950">{{ $value }}</div>
                    @if ($meta)
                        <div class="mt-1 text-xs font-medium text-slate-500">{{ $meta }}</div>
                    @endif
                </div>
            </div>
            @if ($href)
                <x-ui.icon name="chevron-right" class="mt-2 h-4 w-4 text-slate-300 transition group-hover:text-brand-500" />
            @endif
        </div>
    @endif
@php $content = ob_get_clean(); @endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $wrapper]) }}>
        {!! $content !!}
    </a>
@else
    <div {{ $attributes->merge(['class' => $wrapper]) }}>
        {!! $content !!}
    </div>
@endif
