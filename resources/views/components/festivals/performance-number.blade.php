@props(['number'])

@if ($number !== null)
    <span {{ $attributes->class(['me-2 inline-block font-mono text-sm font-semibold tabular-nums']) }} data-festival-performance-number="{{ $number }}">#{{ $number }}</span>
@endif
