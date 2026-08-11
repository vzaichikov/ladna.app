@props([
    'action',
    'resetHref',
])

<form method="GET" action="{{ $action }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
    <div {{ $attributes->class(['grid gap-3 sm:items-end']) }}>
        {{ $slot }}
    </div>
    <div class="mt-4 flex flex-wrap justify-end gap-2">
        <x-ui.button type="submit">
            <x-ui.icon name="filter" class="h-4 w-4" />
            {{ __('app.apply_filters') }}
        </x-ui.button>
        <x-ui.button :href="$resetHref" variant="secondary">{{ __('app.reset_filters') }}</x-ui.button>
    </div>
</form>
