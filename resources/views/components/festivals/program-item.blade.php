@props(['node', 'account', 'edition', 'stage'])

@php
    $item = $node['item'];
    $isHeader = $item->type->isHeader();
    $payload = [
        'id' => $item->id,
        'type' => $item->type->value,
        'festival_entry_id' => $item->festival_entry_id,
        'festival_category_id' => $item->festival_category_id,
        'parent_id' => $item->parent_id,
        'name' => $item->name,
        'starts_at' => $item->starts_at?->timezone($edition->timezone)->format('Y-m-d\TH:i'),
        'ends_at' => $item->ends_at?->timezone($edition->timezone)->format('Y-m-d\TH:i'),
        'notes' => $item->notes,
        'is_published' => $item->published_at !== null,
    ];
@endphp

<li class="min-w-0 max-w-full space-y-2" role="listitem" data-festival-program-item data-item-id="{{ $item->id }}" data-item-type="{{ $item->type->value }}">
    <article class="min-w-0 max-w-full cursor-grab overflow-hidden rounded-xl border p-3 transition active:cursor-grabbing {{ $isHeader ? 'border-brand-200 bg-brand-50/60' : 'border-stone-200 bg-slate-50' }}" draggable="true" data-festival-program-row data-festival-program-drag>
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <x-ui.action-button
                    icon="grip-vertical"
                    :label="__('app.festival_drag_program_item', ['name' => $item->displayName()])"
                    data-festival-program-drag-affordance
                    data-festival-program-sort-control
                />
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="min-w-0 break-words font-semibold text-slate-950 [overflow-wrap:anywhere] {{ $isHeader ? 'text-base' : '' }}">{{ $item->displayName() }}</h3>
                        <span class="rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-600">{{ __('app.festival_schedule_slot_type_'.$item->type->value) }}</span>
                        @if ($item->published_at)
                            <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-800">{{ __('app.published') }}</span>
                        @endif
                    </div>
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-slate-500">
                        @if ($item->starts_at && $item->ends_at)
                            <time>{{ $item->starts_at->timezone($edition->timezone)->format('d.m.Y H:i') }}–{{ $item->ends_at->timezone($edition->timezone)->format('H:i') }}</time>
                        @endif
                        @if ($item->entry)
                            <span>{{ $item->entry->code }}</span>
                        @endif
                        @if ($item->notes)
                            <span class="min-w-0 break-words [overflow-wrap:anywhere]">{{ $item->notes }}</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-1">
                <x-ui.action-button icon="arrow-up" :label="__('app.move_up')" data-festival-program-up data-festival-program-sort-control />
                <x-ui.action-button icon="arrow-down" :label="__('app.move_down')" data-festival-program-down data-festival-program-sort-control />
                <x-ui.action-button icon="indent-increase" :label="__('app.festival_indent_program_item')" data-festival-program-indent data-festival-program-sort-control />
                <x-ui.action-button icon="indent-decrease" :label="__('app.festival_outdent_program_item')" data-festival-program-outdent data-festival-program-sort-control />
                <x-ui.action-button
                    icon="edit"
                    :label="__('app.edit')"
                    data-festival-program-edit
                    data-update-action="{{ route('dashboard.accounts.festivals.schedule.update', [$account, $edition, $item]) }}"
                    data-program-item="{{ json_encode($payload, JSON_THROW_ON_ERROR) }}"
                />
            </div>
        </div>
    </article>

    <ol class="min-w-0 max-w-full space-y-2 border-l border-stone-200 pl-3 sm:pl-5 {{ $node['children'] === [] ? 'hidden' : '' }}" role="list" data-festival-program-list data-parent-id="{{ $item->id }}">
        @foreach ($node['children'] as $child)
            <x-festivals.program-item :node="$child" :$account :$edition :$stage />
        @endforeach
    </ol>
</li>
