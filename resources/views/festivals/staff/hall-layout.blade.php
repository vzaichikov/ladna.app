@extends('layouts.app')

@section('title', __('app.festival_seating_admin.title').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    @php
        $layoutConfig = [
            'state' => $layout,
            'csrf' => csrf_token(),
            'types' => $types,
            'palette' => $palette,
            'maxSeats' => $maxSeats,
            'text' => __('app.festival_seating_admin'),
            'routes' => [
                'save' => route('dashboard.accounts.festivals.hall-layout.update', [$account, $edition]),
                'mode' => route('dashboard.accounts.festivals.hall-layout.mode', [$account, $edition]),
                'block' => route('dashboard.accounts.festivals.hall-layout.block', [$account, $edition]),
                'import' => route('dashboard.accounts.festivals.hall-layout.import', [$account, $edition]),
            ],
        ];
    @endphp
    <dialog id="hall-layout-fullscreen" aria-label="{{ __('app.festival_seating_admin.title') }}" class="fixed inset-0 m-0 h-dvh w-dvw max-h-none max-w-none overflow-y-auto overscroll-contain bg-slate-100 p-3 sm:p-5"></dialog>
    <div class="group/hall space-y-4" data-hall-layout-editor>
        <dialog data-hall-confirm aria-labelledby="hall-confirm-title" aria-describedby="hall-confirm-copy" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl bg-white p-6 text-slate-900 shadow-xl backdrop:bg-slate-950/50">
            <form method="dialog" class="space-y-5">
                <h2 id="hall-confirm-title" class="text-lg font-semibold">{{ __('app.festival_ticket_changes_title') }}</h2>
                <p id="hall-confirm-copy" class="text-sm text-slate-600">{{ __('app.festival_seating_admin.confirm_changes') }}</p>
                <div class="flex flex-wrap justify-end gap-3">
                    <x-ui.button type="submit" value="cancel" variant="secondary" autofocus>{{ __('app.cancel') }}</x-ui.button>
                    <x-ui.button type="submit" value="confirm">{{ __('app.save') }}</x-ui.button>
                </div>
            </form>
        </dialog>
        <script type="application/json" data-hall-config>@json($layoutConfig)</script>

        <x-ui.page-header class="sm:[&>div:last-child]:max-w-[65%] group-data-[hall-expanded]/hall:sticky group-data-[hall-expanded]/hall:top-0 group-data-[hall-expanded]/hall:z-20 group-data-[hall-expanded]/hall:bg-slate-100 group-data-[hall-expanded]/hall:py-2 group-data-[hall-expanded]/hall:[&_.crm-page-copy]:hidden" :title="__('app.festival_seating_admin.title')" :copy="__('app.festival_seating_admin.copy')">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" aria-controls="hall-layout-fullscreen" aria-expanded="false" data-hall-action="fullscreen">
                    <span data-hall-expand-icon><x-ui.icon name="maximize-2" class="size-4" /></span>
                    <span data-hall-collapse-icon hidden><x-ui.icon name="minimize-2" class="size-4" /></span>
                    <span data-hall-fullscreen-label>{{ __('app.festival_seating_admin.fullscreen') }}</span>
                </x-ui.button>
                <x-ui.button type="button" variant="secondary" data-hall-action="preview"><x-ui.icon name="eye" class="size-4" /><span data-hall-preview-label>{{ __('app.festival_seating_admin.preview') }}</span></x-ui.button>
                <x-ui.button type="button" variant="secondary" data-hall-action="save" data-hall-structure>{{ __('app.festival_seating_admin.save_layout') }}</x-ui.button>
                <x-ui.button type="button" data-hall-action="mode">{{ __('app.festival_seating_admin.save_and_enable') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <div role="status" aria-live="polite" class="rounded-xl bg-slate-100 px-4 py-3 text-sm text-slate-800" data-hall-feedback hidden></div>
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-950" data-hall-notice hidden></div>
        <p class="text-sm text-slate-600" data-hall-preview-note hidden>{{ __('app.festival_seating_admin.preview_copy') }}</p>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="{{ __('app.festival_seating_admin.floor') }}">
                <div class="flex flex-wrap gap-1" data-hall-floors></div>
                <x-ui.button type="button" size="sm" variant="secondary" data-hall-action="floor-add" data-hall-structure><x-ui.icon name="plus" class="size-4" />{{ __('app.festival_seating_admin.add_floor') }}</x-ui.button>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700" data-hall-status></span>
        </div>

        <div class="flex flex-wrap gap-1" data-hall-sections hidden></div>

        <div class="grid items-start gap-3 xl:grid-cols-[minmax(0,1fr)_19rem]" data-hall-workspace>
            <x-ui.panel padding="none" class="min-w-0 overflow-hidden">
                <div class="flex flex-wrap items-center gap-1 border-b border-slate-200 px-3 py-2" role="group" aria-label="{{ __('app.festival_seating_admin.tools') }}">
                    @foreach (['select' => 'mouse-pointer-2', 'seat' => 'circle', 'row' => 'ellipsis', 'table' => 'grid-2x2', 'stage' => 'rectangle-horizontal', 'bar' => 'coffee', 'area' => 'square-dashed', 'pan' => 'hand'] as $tool => $icon)
                        <button type="button" class="inline-flex min-h-10 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-slate-700 hover:bg-slate-100 aria-pressed:bg-amber-100 aria-pressed:text-slate-950 disabled:opacity-40" aria-pressed="false" data-hall-tool="{{ $tool }}" @if (! in_array($tool, ['select', 'pan'])) data-hall-structure @endif>
                            <x-ui.icon :name="$icon" class="size-4" />{{ __('app.festival_seating_admin.'.$tool) }}
                        </button>
                    @endforeach
                    <div class="flex gap-1 border-slate-200 sm:ml-1 sm:border-l sm:pl-2" data-hall-structure>
                        @foreach (['undo' => 'undo-2', 'redo' => 'redo-2'] as $action => $icon)
                            <button type="button" aria-label="{{ __('app.festival_seating_admin.'.$action) }}" title="{{ __('app.festival_seating_admin.'.$action) }}" class="grid size-10 place-items-center rounded-lg text-slate-700 hover:bg-slate-100 disabled:opacity-35" data-hall-action="{{ $action }}"><x-ui.icon :name="$icon" class="size-4" /></button>
                        @endforeach
                    </div>
                    <label class="ml-auto flex items-center gap-2 text-xs font-medium text-slate-700" data-hall-count-control hidden>
                        {{ __('app.festival_seating_admin.drawing_count') }}
                        <input type="number" class="crm-field w-16" min="2" max="80" value="8" data-hall-row-count>
                    </label>
                </div>
                <div class="border-b border-slate-200 bg-white px-4 py-2 text-xs font-medium text-slate-600" data-hall-hint></div>
                <div class="h-[68dvh] min-h-96 max-h-[82dvh] overflow-auto group-data-[hall-expanded]/hall:h-[calc(100dvh-20rem)] group-data-[hall-expanded]/hall:max-h-none bg-slate-50 bg-[radial-gradient(#cbd5e1_1px,transparent_1px)] bg-[length:20px_20px] p-3 sm:p-5" data-hall-scroll>
                    <p class="mx-auto mb-3 w-fit rounded-md border border-slate-300 bg-slate-200 px-8 py-1.5 text-xs font-semibold text-slate-800" data-hall-stage hidden></p>
                    <div class="relative mx-auto min-w-56 rounded-xl bg-contain bg-center bg-no-repeat focus-visible:outline-2 focus-visible:outline-indigo-600" data-hall-map tabindex="0" role="group" aria-label="{{ __('app.festival_seating_admin.canvas') }}">
                        <div data-hall-blocks></div>
                        <div data-hall-groups></div>
                        <div data-hall-points></div>
                        <div class="pointer-events-none absolute inset-0" data-hall-overlay></div>
                    </div>
                    <div class="mx-auto max-w-sm space-y-3 rounded-xl border border-slate-200 bg-white p-5 text-center" data-hall-empty>
                        <p class="text-sm text-slate-600" data-hall-empty-text>{{ __('app.festival_seating_admin.empty_map') }}</p>
                        <x-ui.button type="button" size="sm" data-hall-action="start" data-hall-structure>{{ __('app.festival_seating_admin.add_floor') }}</x-ui.button>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-4 py-2 text-xs text-slate-600">
                    <p class="font-medium" data-hall-count></p>
                    <label class="flex items-center gap-2 font-medium">
                        {{ __('app.festival_seating_admin.zoom') }}
                        <input type="range" min="50" max="250" step="10" value="100" data-hall-zoom>
                        <output class="w-9 tabular-nums" data-hall-zoom-value>100%</output>
                    </label>
                </div>
                <ul class="flex flex-wrap gap-x-4 gap-y-2 border-t border-slate-100 px-4 py-2 text-xs text-slate-700" data-hall-legend></ul>
            </x-ui.panel>

            <x-ui.panel class="space-y-4 xl:sticky xl:top-4 xl:max-h-[82dvh] xl:overflow-y-auto group-data-[hall-expanded]/hall:xl:max-h-[calc(100dvh-13rem)]" data-hall-inspector>
                <div class="flex flex-wrap items-center justify-between gap-1">
                    <p class="font-semibold text-slate-900" data-hall-selection></p>
                    <button type="button" class="rounded-md px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100" data-hall-action="clear">{{ __('app.festival_seating_admin.clear_selection') }}</button>
                </div>
                <p class="text-xs leading-relaxed text-slate-500" data-hall-selection-hint>{{ __('app.festival_seating_admin.selection_hint') }}</p>
                <div class="space-y-2" data-hall-seat-pricing data-hall-structure>
                    <div class="space-y-1" data-hall-imported-colors hidden>
                        <p class="text-xs font-medium text-slate-600">{{ __('app.festival_seating_admin.imported_colors') }}</p>
                        <div class="flex flex-wrap gap-1" data-hall-colors></div>
                    </div>
                    <p class="text-xs font-medium text-slate-600" data-hall-type-hint></p>
                    <div class="grid gap-1.5" data-hall-ticket-types></div>
                    <a class="block rounded-md py-1 text-xs font-semibold text-slate-700 underline underline-offset-2" href="{{ route('dashboard.accounts.festivals.admission-types.create', [$account, $edition, 'assigned' => 1]) }}">{{ __('app.festival_add_admission_type') }}</a>
                </div>
                <section class="border-t border-slate-200 pt-3" data-hall-properties-panel data-hall-structure>
                    <h3 class="text-sm font-semibold text-slate-900">{{ __('app.festival_seating_admin.properties') }}</h3>
                    <div class="mt-3 space-y-3">
                        <label class="block"><span class="crm-label">{{ __('app.festival_seating_admin.section') }}</span><select class="crm-field" data-hall-property="section"></select></label>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label><span class="crm-label">{{ __('app.festival_seating_admin.group') }}</span><select class="crm-field" data-hall-property="group_kind"><option value="row">{{ __('app.festival_seating_admin.row') }}</option><option value="table">{{ __('app.festival_seating_admin.table') }}</option></select></label>
                            <label><span class="crm-label">{{ __('app.festival_seating_admin.group_name') }}</span><input class="crm-field" maxlength="40" data-hall-property="group_label"></label>
                        </div>
                        <label class="block"><span class="crm-label">{{ __('app.festival_seating_admin.seat_number') }}</span><input class="crm-field" maxlength="24" data-hall-property="seat_number" data-hall-single></label>
                        <label class="block"><span class="crm-label">{{ __('app.festival_seating_admin.accessibility') }}</span><input class="crm-field" maxlength="255" data-hall-property="accessibility"></label>
                        <label class="block"><span class="crm-label">{{ __('app.festival_seating_admin.sightline') }}</span><input class="crm-field" maxlength="255" data-hall-property="sightline"></label>
                    </div>
                </section>
                <div class="flex flex-wrap gap-2" data-hall-selection-actions hidden>
                    <x-ui.button type="button" size="sm" variant="secondary" data-hall-action="block">{{ __('app.festival_seating_admin.block') }}</x-ui.button>
                    <x-ui.button type="button" size="sm" variant="secondary" data-hall-action="unblock">{{ __('app.festival_seating_admin.unblock') }}</x-ui.button>
                    <x-ui.button type="button" size="sm" variant="secondary" data-hall-action="seat-delete" data-hall-structure><x-ui.icon name="trash-2" class="size-4" />{{ __('app.festival_seating_admin.delete_seats') }}</x-ui.button>
                </div>
                <section class="space-y-3 border-t border-slate-200 pt-3" data-hall-block-panel hidden>
                    <h3 class="text-sm font-semibold text-slate-900">{{ __('app.festival_seating_admin.block_properties') }}</h3>
                    <label class="block"><span class="crm-label">{{ __('app.festival_seating_admin.block_label') }}</span><input class="crm-field" maxlength="80" data-hall-block-property="label"></label>
                    <label class="block"><span class="crm-label">{{ __('app.festival_seating_admin.block_kind') }}</span><select class="crm-field" data-hall-block-property="kind">
                        @foreach (['stage', 'bar', 'area'] as $kind)
                            <option value="{{ $kind }}">{{ __('app.festival_seating_admin.'.$kind) }}</option>
                        @endforeach
                    </select></label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['width', 'height'] as $dimension)
                            <label><span class="crm-label">{{ __('app.festival_seating_admin.block_'.$dimension) }}</span><input class="crm-field" type="number" min="1" max="100" step="0.1" data-hall-block-property="{{ $dimension }}"></label>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-500">{{ __('app.festival_seating_admin.block_hint') }}</p>
                    <x-ui.button type="button" size="sm" variant="secondary" data-hall-action="block-delete"><x-ui.icon name="trash-2" class="size-4" />{{ __('app.festival_seating_admin.delete_block') }}</x-ui.button>
                </section>
                <section class="space-y-3 border-t border-slate-200 pt-3" data-hall-section-panel data-hall-structure>
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">{{ __('app.festival_seating_admin.sections') }}</h3>
                        <button type="button" class="rounded-md px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100" data-hall-action="section-add"><x-ui.icon name="plus" class="inline size-3.5" />{{ __('app.festival_seating_admin.add_section') }}</button>
                    </div>
                    <div class="space-y-1" data-hall-section-list></div>
                    <div class="space-y-2" data-hall-section-editor hidden>
                        <label class="block"><span class="crm-label">{{ __('app.festival_seating_admin.section_name') }}</span><input class="crm-field" maxlength="80" data-hall-section-name></label>
                        <p role="alert" class="text-xs font-medium text-rose-700" data-hall-section-error hidden></p>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="button" size="sm" variant="secondary" data-hall-action="section-rename">{{ __('app.festival_seating_admin.rename_section') }}</x-ui.button>
                            <x-ui.button type="button" size="sm" variant="secondary" data-hall-action="section-remove"><x-ui.icon name="trash-2" class="size-4" />{{ __('app.festival_seating_admin.remove_section') }}</x-ui.button>
                        </div>
                        <div class="space-y-2 rounded-lg bg-slate-50 p-3" data-hall-section-removal hidden>
                            <label class="block"><span class="crm-label">{{ __('app.festival_seating_admin.move_section_seats') }}</span><select class="crm-field" data-hall-section-target></select></label>
                            <div class="flex gap-2">
                                <x-ui.button type="button" size="sm" variant="secondary" data-hall-action="section-remove-confirm">{{ __('app.festival_seating_admin.confirm_remove_section') }}</x-ui.button>
                                <button type="button" class="px-2 text-xs font-medium text-slate-600" data-hall-action="section-remove-cancel">{{ __('app.festival_seating_admin.cancel') }}</button>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500" data-hall-section-hint></p>
                </section>
                <section class="space-y-2 border-t border-slate-200 pt-3" data-hall-block-list-panel data-hall-structure>
                    <h3 class="text-sm font-semibold text-slate-900">{{ __('app.festival_seating_admin.venue_blocks') }}</h3>
                    <div class="space-y-1" data-hall-block-list></div>
                    <p class="text-xs text-slate-500">{{ __('app.festival_seating_admin.blocks_hint') }}</p>
                </section>
                <section class="space-y-3 border-t border-slate-200 pt-3" data-hall-floor-panel data-hall-structure>
                    <h3 class="text-sm font-semibold text-slate-900">{{ __('app.festival_seating_admin.floor_settings') }}</h3>
                    <label class="block"><span class="crm-label">{{ __('app.festival_seating_admin.floor_name') }}</span><input class="crm-field" data-hall-floor-name maxlength="80"></label>
                    <label class="block" data-hall-legacy-stage><span class="crm-label">{{ __('app.festival_seating_admin.stage') }}</span><input class="crm-field" data-hall-stage-name maxlength="80"></label>
                    <x-ui.button type="button" size="sm" variant="secondary" data-hall-action="floor-remove"><x-ui.icon name="trash-2" class="size-4" />{{ __('app.festival_seating_admin.remove_floor') }}</x-ui.button>
                </section>
            </x-ui.panel>
        </div>

        <x-ui.panel data-hall-structure>
            <details>
                <summary class="cursor-pointer font-semibold text-slate-950">{{ __('app.festival_seating_admin.import_title') }}</summary>
                <div class="mt-4 grid gap-5 lg:grid-cols-2">
                    <div class="space-y-3 text-sm text-slate-700">
                        <p>{{ __('app.festival_seating_admin.import_help') }}</p>
                        <p>{{ __('app.festival_seating_admin.import_flow') }}</p>
                        <p>{{ __('app.festival_seating_admin.import_limits') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button :href="route('dashboard.accounts.festivals.hall-layout.template', [$account, $edition, 'template'])" variant="secondary">{{ __('app.festival_seating_admin.template') }}</x-ui.button>
                            <x-ui.button :href="route('dashboard.accounts.festivals.hall-layout.template', [$account, $edition, 'example'])" variant="secondary">{{ __('app.festival_seating_admin.example') }}</x-ui.button>
                        </div>
                        <div class="flex flex-wrap items-end gap-2">
                            <input type="file" accept="image/png" aria-label="{{ __('app.festival_seating_admin.import_title') }}" class="max-w-full text-sm" data-hall-import-file>
                            <x-ui.button type="button" data-hall-action="import">{{ __('app.festival_seating_admin.import') }}</x-ui.button>
                        </div>
                        <p role="status" class="text-sm font-medium" data-hall-import-note></p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($palette as $color)
                                <span class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 py-1 font-mono text-xs">
                                    <span class="size-2.5 rounded-full" style="background-color: {{ $color }}"></span>{{ $color }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                    <img class="w-full rounded-xl border border-slate-200" loading="lazy" width="900" height="600" alt="{{ __('app.festival_seating_admin.example_alt') }}" src="{{ route('dashboard.accounts.festivals.hall-layout.template', [$account, $edition, 'example', 'preview' => 1]) }}">
                </div>
            </details>
        </x-ui.panel>
    </div>
</x-festivals.staff.workspace>
@endsection
