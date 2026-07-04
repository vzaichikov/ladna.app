@props([
    'label',
    'value',
    'meta' => null,
    'icon' => 'sparkles',
    'accent' => 'violet',
    'href' => null,
])

@php
    $accents = [
        'violet' => 'bg-violet-crm-100 text-brand-700',
        'brand' => 'bg-brand-100 text-brand-700',
        'emerald' => 'bg-emerald-50 text-emerald-700',
        'amber' => 'bg-amber-50 text-amber-700',
        'slate' => 'bg-slate-100 text-slate-700',
    ];
    $wrapper = 'group block rounded-xl border border-stone-200 bg-white p-4 shadow-crm transition hover:-translate-y-0.5 hover:border-violet-crm-100 hover:shadow-lg';
@endphp

@php ob_start(); @endphp
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
