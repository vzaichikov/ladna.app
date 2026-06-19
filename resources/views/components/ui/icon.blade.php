@props([
    'name' => 'sparkles',
    'class' => 'h-5 w-5',
])

@php
    $iconName = config('icons.aliases.'.$name, $name);
@endphp

<i data-lucide="{{ $iconName }}" {{ $attributes->merge(['class' => $class]) }} aria-hidden="true"></i>
