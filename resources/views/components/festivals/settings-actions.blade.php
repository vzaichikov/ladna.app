@props([
    'toggleRoute',
    'moveRoute',
    'active',
    'editRoute',
    'showOrdering' => true,
    'canMoveUp' => true,
    'canMoveDown' => true,
])

<div {{ $attributes->class(['flex flex-wrap items-center justify-end gap-2']) }}>
    @if ($showOrdering)
        <form method="POST" action="{{ $moveRoute }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="direction" value="up">
            <x-ui.action-button type="submit" icon="arrow-up" :label="__('app.move_up')" :disabled="! $canMoveUp" />
        </form>
        <form method="POST" action="{{ $moveRoute }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="direction" value="down">
            <x-ui.action-button type="submit" icon="arrow-down" :label="__('app.move_down')" :disabled="! $canMoveDown" />
        </form>
    @endif

    <x-ui.action-button :href="$editRoute" icon="edit" :label="__('app.edit')" />

    <form method="POST" action="{{ $toggleRoute }}">
        @csrf
        @method('PATCH')
        <x-ui.action-button
            type="submit"
            :variant="$active ? 'danger' : 'success'"
            :icon="$active ? 'power' : 'circle-check'"
            :label="$active ? __('app.deactivate') : __('app.activate')"
        />
    </form>
</div>
