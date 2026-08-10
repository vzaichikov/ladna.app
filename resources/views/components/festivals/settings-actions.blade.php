@props(['toggleRoute', 'moveRoute', 'active', 'editTarget' => null])

<div {{ $attributes->class(['flex flex-wrap items-center justify-end gap-2']) }}>
    <form method="POST" action="{{ $moveRoute }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="direction" value="up">
        <x-ui.action-button type="submit" icon="arrow-up" :label="__('app.move_up')" />
    </form>
    <form method="POST" action="{{ $moveRoute }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="direction" value="down">
        <x-ui.action-button type="submit" icon="arrow-down" :label="__('app.move_down')" />
    </form>
    @if ($editTarget)
        <x-ui.action-button
            type="button"
            icon="edit"
            :label="__('app.edit')"
            data-festival-edit-toggle
            aria-controls="{{ $editTarget }}"
            aria-expanded="false"
        />
    @endif
    <form method="POST" action="{{ $toggleRoute }}">
        @csrf
        @method('PATCH')
        <x-ui.button type="submit" size="sm" :variant="$active ? 'danger' : 'success'">
            {{ $active ? __('app.deactivate') : __('app.activate') }}
        </x-ui.button>
    </form>
</div>
