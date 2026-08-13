@props([
    'definition',
    'value' => null,
])

@php
    $inputType = $definition->input_type;
    $optionLabels = collect($definition->options ?? [])
        ->filter(fn (mixed $option): bool => is_array($option) && array_key_exists('value', $option) && array_key_exists('label', $option))
        ->mapWithKeys(fn (array $option): array => [(string) $option['value'] => (string) $option['label']]);
    $isEmpty = $value === null || $value === '' || $value === [];

    if ($isEmpty) {
        $displayValue = __('app.not_set');
    } elseif (in_array($inputType, [\App\Enums\FestivalRequirementInputType::Boolean, \App\Enums\FestivalRequirementInputType::Agreement], true)) {
        $displayValue = in_array($value, [true, 1, '1'], true) ? __('app.yes') : __('app.no');
    } elseif (in_array($inputType, [\App\Enums\FestivalRequirementInputType::SingleSelect, \App\Enums\FestivalRequirementInputType::MultiSelect], true)) {
        $displayValue = collect(is_array($value) ? $value : [$value])
            ->map(fn (mixed $selectedValue): string => $optionLabels->get((string) $selectedValue, (string) $selectedValue))
            ->join(', ');
    } elseif (is_array($value)) {
        $displayValue = collect($value)
            ->filter(fn (mixed $item): bool => is_scalar($item))
            ->map(fn (mixed $item): string => (string) $item)
            ->join(', ');
    } else {
        $displayValue = (string) $value;
    }

    $isExternalUrl = $inputType === \App\Enums\FestivalRequirementInputType::Url
        && \Illuminate\Support\Str::startsWith(\Illuminate\Support\Str::lower($displayValue), ['http://', 'https://']);
@endphp

@if ($isExternalUrl)
    <a href="{{ $displayValue }}" target="_blank" rel="noopener noreferrer" {{ $attributes->merge(['class' => 'break-all text-brand-700 underline decoration-brand-200 underline-offset-2 hover:text-brand-800']) }}>{{ $displayValue }}</a>
@else
    <span {{ $attributes->merge(['class' => 'whitespace-pre-line break-words']) }}>{{ $displayValue }}</span>
@endif
