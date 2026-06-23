@props([
    'href' => null,
    'type' => 'button',
    'variant' => 'secondary',
    'size' => 'sm',
    'icon' => 'edit',
    'label' => null,
    'disabled' => false,
])

@php
    $resolvedLabel = $label ?? '';
    $iconClass = $size === 'lg' ? 'h-5 w-5' : 'h-4 w-4';
@endphp

<x-ui.button
    :href="$href"
    :type="$type"
    :variant="$variant"
    :size="$size"
    :icon-only="true"
    :disabled="$disabled"
    title="{{ $attributes->get('title', $resolvedLabel) }}"
    aria-label="{{ $attributes->get('aria-label', $resolvedLabel) }}"
    {{ $attributes->except(['title', 'aria-label'])->class('shrink-0') }}
>
    <x-ui.icon :name="$icon" :class="$iconClass" />
    <span class="sr-only">{{ $resolvedLabel }}</span>
</x-ui.button>
