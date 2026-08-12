@extends('layouts.app')

@section('title', __('app.festival_tab_program').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_program_title')" :copy="__('app.festival_program_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('help.show', 'festivals').'#help-section-festivals-program-scenes'" variant="secondary" target="_blank" rel="noopener">
                <x-ui.icon name="circle-help" class="h-4 w-4" />
                {{ __('app.help') }}
            </x-ui.button>
            @if ($activeStage)
                <x-ui.button type="button" data-festival-program-add>
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.festival_add_program_item') }}
                </x-ui.button>
            @endif
            <x-ui.button :href="route('dashboard.accounts.festivals.settings.stages', [$account, $edition])" variant="secondary">
                <x-ui.icon name="settings" class="h-4 w-4" />
                {{ __('app.festival_manage_scenes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($stages->isNotEmpty())
        <nav class="max-w-full min-w-0 overflow-x-auto pb-1" aria-label="{{ __('app.festival_scenes') }}" data-festival-scene-tabs>
            <div class="flex w-max min-w-full gap-2 border-b border-stone-200" role="tablist">
                @foreach ($stages as $stage)
                    <a
                        href="{{ route('dashboard.accounts.festivals.program', ['account' => $account, 'festivalEdition' => $edition, 'scene' => $stage->id]) }}"
                        id="festival-scene-tab-{{ $stage->id }}"
                        role="tab"
                        aria-controls="festival-scene-panel-{{ $stage->id }}"
                        aria-selected="{{ $activeStage?->is($stage) ? 'true' : 'false' }}"
                        tabindex="{{ $activeStage?->is($stage) ? '0' : '-1' }}"
                        class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeStage?->is($stage) ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:border-stone-300 hover:text-slate-800' }}"
                    >
                        {{ $stage->name }}
                        <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600">{{ $stage->slots_count }}</span>
                        @unless ($stage->is_active)
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{{ __('app.inactive') }}</span>
                        @endunless
                    </a>
                @endforeach
            </div>
        </nav>
    @endif

    @if ($activeStage)
        <section
            id="festival-scene-panel-{{ $activeStage->id }}"
            class="min-w-0 max-w-full overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm"
            role="tabpanel"
            aria-labelledby="festival-scene-tab-{{ $activeStage->id }}"
            data-festival-program
            data-order-url="{{ route('dashboard.accounts.festivals.schedule.reorder', [$account, $edition, $activeStage]) }}"
            data-order-error="{{ __('app.festival_program_order_error') }}"
        >
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-5 py-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950">{{ $activeStage->name }}</h2>
                    @if ($activeStage->description)
                        <p class="mt-1 text-sm text-slate-500">{{ $activeStage->description }}</p>
                    @endif
                </div>
                <span class="text-sm text-slate-500">{{ $edition->timezone }}</span>
            </div>

            <div class="min-w-0 p-4 sm:p-5">
                <p class="mb-4 hidden rounded-lg px-3 py-2 text-sm" role="status" data-festival-program-status></p>
                @if ($programTree !== [])
                    <ol class="min-w-0 space-y-2" role="list" data-festival-program-list data-parent-id="">
                        @foreach ($programTree as $node)
                            <x-festivals.program-item :$node :$account :$edition :stage="$activeStage" />
                        @endforeach
                    </ol>
                @else
                    <ol class="hidden min-w-0 space-y-2" role="list" data-festival-program-list data-parent-id=""></ol>
                    <x-ui.empty-state :title="__('app.festival_scene_empty')" icon="calendar-days" data-festival-program-empty>
                        <x-ui.button type="button" class="mt-3" data-festival-program-add>{{ __('app.festival_add_program_item') }}</x-ui.button>
                    </x-ui.empty-state>
                @endif
            </div>
        </section>

        @php
            $editingItemId = (int) old('editing_item_id', 0);
            $editingItem = $editingItemId > 0 ? $programItems->firstWhere('id', $editingItemId) : null;
            $festivalStartDateTime = $edition->starts_at?->timezone($edition->timezone)->format('Y-m-d\TH:i');
            $modalAction = $editingItem
                ? route('dashboard.accounts.festivals.schedule.update', [$account, $edition, $editingItem])
                : route('dashboard.accounts.festivals.schedule.store', [$account, $edition]);
            $modalType = old('type', $editingItem?->type->value ?? 'performance');
        @endphp
        <div
            class="fixed inset-0 z-50 {{ $errors->any() ? 'flex' : 'hidden' }} items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="festival-program-modal-title"
            data-festival-program-modal
            data-store-action="{{ route('dashboard.accounts.festivals.schedule.store', [$account, $edition]) }}"
            data-add-title="{{ __('app.festival_add_program_item') }}"
            data-edit-title="{{ __('app.festival_edit_program_item') }}"
            @if ($errors->any()) data-auto-open="true" @endif
        >
            <div class="flex max-h-[calc(100vh-1.5rem)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl sm:max-h-[calc(100vh-2rem)]">
                <div class="flex items-center justify-between gap-4 border-b border-stone-200 px-5 py-4">
                    <h2 id="festival-program-modal-title" class="text-xl font-semibold text-slate-950" data-festival-program-modal-title>
                        {{ $editingItem ? __('app.festival_edit_program_item') : __('app.festival_add_program_item') }}
                    </h2>
                    <x-ui.action-button icon="x" :label="__('app.close')" data-festival-program-close />
                </div>
                <form method="POST" action="{{ $modalAction }}" class="min-h-0 flex-1 overflow-y-auto p-5" data-festival-program-form>
                    @csrf
                    <input type="hidden" name="_method" value="PUT" @disabled(! $editingItem) data-festival-program-method>
                    <input type="hidden" name="editing_item_id" value="{{ old('editing_item_id', $editingItem?->id) }}" data-festival-program-editing-id>
                    <input type="hidden" name="festival_stage_id" value="{{ $activeStage->id }}">

                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="sm:col-span-2">
                            <span class="crm-label">{{ __('app.type') }}</span>
                            <select name="type" required class="crm-field" data-festival-program-type>
                                @foreach (\App\Enums\FestivalScheduleSlotType::cases() as $type)
                                    <option value="{{ $type->value }}" @selected($modalType === $type->value)>{{ __('app.festival_schedule_slot_type_'.$type->value) }}</option>
                                @endforeach
                            </select>
                            @error('type') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label class="sm:col-span-2" data-festival-program-field="entry">
                            <span class="crm-label">{{ __('app.performance') }}</span>
                            <select name="festival_entry_id" class="crm-field">
                                <option value="">{{ __('app.select_option') }}</option>
                                @foreach ($entries as $entry)
                                    <option value="{{ $entry->id }}" @selected((int) old('festival_entry_id', $editingItem?->festival_entry_id) === $entry->id)>{{ $entry->code }} · {{ $entry->entry_name }}</option>
                                @endforeach
                            </select>
                            @error('festival_entry_id') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label class="sm:col-span-2" data-festival-program-field="category">
                            <span class="crm-label">{{ __('app.festival_category') }}</span>
                            <select name="festival_category_id" class="crm-field">
                                <option value="">{{ __('app.select_option') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected((int) old('festival_category_id', $editingItem?->festival_category_id) === $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                            @error('festival_category_id') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label class="sm:col-span-2" data-festival-program-field="name">
                            <span class="crm-label">{{ __('app.festival_program_item_name') }}</span>
                            <input name="name" maxlength="255" value="{{ old('name', $editingItem?->name) }}" class="crm-field" placeholder="{{ __('app.festival_program_item_name_placeholder') }}">
                            @error('name') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label class="sm:col-span-2">
                            <span class="crm-label">{{ __('app.festival_parent_header') }}</span>
                            <select name="parent_id" class="crm-field">
                                <option value="">{{ __('app.festival_no_parent_header') }}</option>
                                @foreach ($programItems->filter(fn ($item) => $item->type->isHeader()) as $header)
                                    @continue($editingItem?->is($header))
                                    <option value="{{ $header->id }}" @selected((int) old('parent_id', $editingItem?->parent_id) === $header->id)>{{ $header->displayName() }}</option>
                                @endforeach
                            </select>
                            @error('parent_id') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label data-festival-program-field="times">
                            <span class="crm-label">{{ __('app.starts_at') }}</span>
                            <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $editingItem?->starts_at?->timezone($edition->timezone)->format('Y-m-d\TH:i') ?? $festivalStartDateTime) }}" class="crm-field">
                            @error('starts_at') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        <label data-festival-program-field="times">
                            <span class="crm-label">{{ __('app.ends_at') }}</span>
                            <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $editingItem?->ends_at?->timezone($edition->timezone)->format('Y-m-d\TH:i') ?? $festivalStartDateTime) }}" class="crm-field">
                            @error('ends_at') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label class="sm:col-span-2" data-festival-program-field="reschedule">
                            <span class="crm-label">{{ __('app.festival_reschedule_reason') }}</span>
                            <input name="reschedule_reason" maxlength="3000" value="{{ old('reschedule_reason') }}" class="crm-field">
                            @error('reschedule_reason') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label class="sm:col-span-2">
                            <span class="crm-label">{{ __('app.notes') }}</span>
                            <textarea name="notes" rows="3" maxlength="3000" class="crm-field">{{ old('notes', $editingItem?->notes) }}</textarea>
                            @error('notes') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <input type="hidden" name="is_published" value="0">
                        <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2" data-festival-program-field="publish">
                            <input type="checkbox" name="is_published" value="1" class="crm-checkbox" @checked(old('is_published', $editingItem?->published_at !== null))>
                            {{ __('app.publish') }}
                        </label>
                    </div>

                    <div class="mt-6 flex flex-wrap justify-end gap-2 border-t border-stone-200 pt-4">
                        <x-ui.button type="button" variant="secondary" data-festival-program-close>{{ __('app.cancel') }}</x-ui.button>
                        <x-ui.button type="submit">
                            <x-ui.icon name="save" class="h-4 w-4" />
                            {{ __('app.save') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @else
        <x-ui.empty-state :title="__('app.festival_scenes_empty')" icon="theater">
            <p>{{ __('app.festival_scenes_required_for_program') }}</p>
            <x-ui.button :href="route('dashboard.accounts.festivals.stages.create', [$account, $edition])" class="mt-3">{{ __('app.festival_add_scene') }}</x-ui.button>
        </x-ui.empty-state>
    @endif
</x-festivals.staff.workspace>
@endsection
