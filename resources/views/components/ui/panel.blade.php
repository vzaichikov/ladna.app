@props([
    'padding' => 'md',
])

@php
    $paddings = [
        'none' => '',
        'sm' => 'p-4',
        'md' => 'p-5',
        'lg' => 'p-6',
    ];
@endphp

<section {{ $attributes->merge(['class' => 'rounded-xl border border-stone-200 bg-white shadow-crm '.($paddings[$padding] ?? $paddings['md'])]) }}>
    {{ $slot }}
</section>
