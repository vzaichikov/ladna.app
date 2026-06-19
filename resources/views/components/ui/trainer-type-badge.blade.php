@props([
    'trainerType' => null,
])

@if ($trainerType)
    <span
        {{ $attributes->merge(['class' => 'inline-flex h-7 w-fit shrink-0 items-center gap-1.5 rounded-md border px-2 text-xs font-semibold']) }}
        style="border-color: {{ $trainerType->color }}33; background-color: {{ $trainerType->color }}14; color: {{ $trainerType->color }};"
    >
        <x-ui.icon :name="$trainerType->icon" class="h-3.5 w-3.5" />
        {{ $trainerType->name }}
    </span>
@else
    <span {{ $attributes->merge(['class' => 'crm-status-muted']) }}>
        {{ __('app.not_set') }}
    </span>
@endif
