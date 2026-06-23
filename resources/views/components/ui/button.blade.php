@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'primary',
    'size' => 'md',
    'iconOnly' => false,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition crm-focus disabled:pointer-events-none disabled:opacity-50';
    $defaultSizes = [
        'sm' => 'px-3 py-2 text-xs',
        'md' => 'px-4 py-2.5 text-sm',
        'lg' => 'px-5 py-3 text-sm',
    ];
    $iconOnlySizes = [
        'sm' => 'h-9 w-9 p-0 text-xs',
        'md' => 'h-10 w-10 p-0 text-sm',
        'lg' => 'h-11 w-11 p-0 text-sm',
    ];
    $sizes = $iconOnly ? $iconOnlySizes : $defaultSizes;
    $variants = [
        'primary' => 'bg-brand-600 text-white shadow-sm shadow-brand-600/20 hover:bg-brand-700',
        'brand' => 'bg-violet-crm-500 text-white shadow-sm shadow-violet-crm-500/20 hover:bg-brand-600',
        'secondary' => 'border border-stone-200 bg-white text-slate-800 shadow-xs hover:border-brand-100 hover:bg-brand-50',
        'ghost' => 'text-slate-600 hover:bg-brand-50 hover:text-slate-950',
        'danger' => 'border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100',
        'sidebar' => 'bg-white/10 text-white hover:bg-white/15',
    ];
    $classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
