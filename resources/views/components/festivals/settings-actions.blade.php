@props([
    'toggleRoute',
    'moveRoute',
    'active',
    'editRoute',
    'showOrdering' => true,
    'canMoveUp' => true,
    'canMoveDown' => true,
    'moveParameters' => [],
    'deleteRoute' => null,
    'deleteLabel' => null,
    'deleteConfirmTitle' => null,
    'deleteConfirmBody' => null,
])

<div {{ $attributes->class(['flex flex-wrap items-center justify-end gap-2']) }}>
    @if ($showOrdering)
        <form method="POST" action="{{ $moveRoute }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="direction" value="up">
            @foreach ($moveParameters as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <x-ui.action-button type="submit" icon="arrow-up" :label="__('app.move_up')" :disabled="! $canMoveUp" />
        </form>
        <form method="POST" action="{{ $moveRoute }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="direction" value="down">
            @foreach ($moveParameters as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
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

    @if ($deleteRoute)
        <form
            method="POST"
            action="{{ $deleteRoute }}"
            data-confirm-delete
            @if ($deleteConfirmTitle) data-confirm-title="{{ $deleteConfirmTitle }}" @endif
            @if ($deleteConfirmBody) data-confirm-body="{{ $deleteConfirmBody }}" @endif
            @if ($deleteLabel) data-confirm-accept="{{ $deleteLabel }}" @endif
        >
            @csrf
            @method('DELETE')
            <x-ui.action-button type="submit" variant="danger" icon="trash" :label="$deleteLabel ?: __('app.delete')" />
        </form>
    @endif
</div>
