@extends('layouts.app')

@section('title', __('app.festival_tab_program').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <div>
        <p class="crm-page-kicker">{{ __('app.festival_tab_program') }}</p>
        <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('app.festival_program_title') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_program_copy') }}</p>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-xl font-semibold">{{ __('app.festival_schedule') }}</h2>
                <span class="text-sm text-slate-500">{{ $edition->timezone }}</span>
            </div>

            <div class="mt-4 space-y-5">
                @forelse ($edition->stages as $stage)
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold text-slate-950">{{ $stage->name }}</h3>
                            <span class="text-xs text-slate-500">{{ trans_choice('app.festival_slots_count', $stage->slots->count(), ['count' => $stage->slots->count()]) }}</span>
                        </div>
                        <div class="mt-2 space-y-2">
                            @forelse ($stage->slots as $slot)
                                <details class="rounded-xl border border-stone-200 bg-slate-50 p-3">
                                    <summary class="cursor-pointer list-none">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <strong>{{ $slot->entry->performer_name }}</strong>
                                                <span class="ml-2 text-sm text-slate-500">{{ $slot->entry->code }} · {{ $slot->type === \App\Enums\FestivalScheduleSlotType::Rehearsal ? __('app.festival_rehearsal') : __('app.performance') }}</span>
                                            </div>
                                            <div class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                                                <time>{{ $slot->starts_at->timezone($edition->timezone)->format('d.m H:i') }}–{{ $slot->ends_at->timezone($edition->timezone)->format('H:i') }}</time>
                                                <span class="{{ $slot->published_at ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-700' }} rounded-full px-2 py-1 text-xs">{{ $slot->published_at ? __('app.published') : __('app.draft') }}</span>
                                            </div>
                                        </div>
                                    </summary>
                                    <form method="POST" action="{{ route('dashboard.accounts.festivals.schedule.update', [$account, $edition, $slot]) }}" class="mt-4 grid gap-3 border-t border-stone-200 pt-4 sm:grid-cols-2">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="festival_stage_id" value="{{ $stage->id }}">
                                        <input type="hidden" name="festival_entry_id" value="{{ $slot->entry->id }}">
                                        <label>
                                            <span class="crm-label">{{ __('app.type') }}</span>
                                            <select name="type" class="crm-field">
                                                <option value="performance" @selected($slot->type === \App\Enums\FestivalScheduleSlotType::Performance)>{{ __('app.performance') }}</option>
                                                <option value="rehearsal" @selected($slot->type === \App\Enums\FestivalScheduleSlotType::Rehearsal)>{{ __('app.festival_rehearsal') }}</option>
                                            </select>
                                        </label>
                                        <label>
                                            <span class="crm-label">{{ __('app.festival_reschedule_reason') }}</span>
                                            <input name="reschedule_reason" class="crm-field">
                                        </label>
                                        <label>
                                            <span class="crm-label">{{ __('app.starts_at') }}</span>
                                            <input type="datetime-local" name="starts_at" required value="{{ $slot->starts_at->timezone($edition->timezone)->format('Y-m-d\TH:i') }}" class="crm-field">
                                        </label>
                                        <label>
                                            <span class="crm-label">{{ __('app.ends_at') }}</span>
                                            <input type="datetime-local" name="ends_at" required value="{{ $slot->ends_at->timezone($edition->timezone)->format('Y-m-d\TH:i') }}" class="crm-field">
                                        </label>
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_published" value="1" class="crm-checkbox" @checked($slot->published_at)>{{ __('app.publish') }}</label>
                                        <div class="flex justify-end"><x-ui.button type="submit" size="sm">{{ __('app.save') }}</x-ui.button></div>
                                    </form>
                                </details>
                            @empty
                                <p class="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">{{ __('app.festival_stage_empty') }}</p>
                            @endforelse
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="calendar-days">{{ __('app.festival_stages_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
        </section>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <h2 class="text-lg font-semibold">{{ __('app.festival_add_slot') }}</h2>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.schedule.store', [$account, $edition]) }}" class="mt-4 space-y-3">
                    @csrf
                    <label>
                        <span class="crm-label">{{ __('app.festival_stage') }}</span>
                        <select name="festival_stage_id" required class="crm-field">
                            @foreach ($edition->stages as $stage)
                                <option value="{{ $stage->id }}">{{ $stage->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.performance') }}</span>
                        <select name="festival_entry_id" required class="crm-field">
                            @foreach ($entries as $entry)
                                <option value="{{ $entry->id }}">{{ $entry->code }} · {{ $entry->performer_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.type') }}</span>
                        <select name="type" class="crm-field">
                            <option value="performance">{{ __('app.performance') }}</option>
                            <option value="rehearsal">{{ __('app.festival_rehearsal') }}</option>
                        </select>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label><span class="crm-label">{{ __('app.starts_at') }}</span><input type="datetime-local" name="starts_at" required class="crm-field"></label>
                        <label><span class="crm-label">{{ __('app.ends_at') }}</span><input type="datetime-local" name="ends_at" required class="crm-field"></label>
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_published" value="1" class="crm-checkbox">{{ __('app.publish') }}</label>
                    <x-ui.button type="submit" class="w-full">{{ __('app.add') }}</x-ui.button>
                </form>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <h2 class="text-lg font-semibold">{{ __('app.festival_add_stage') }}</h2>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.stages.store', [$account, $edition]) }}" class="mt-4 space-y-3">
                    @csrf
                    <label><span class="crm-label">{{ __('app.name') }}</span><input name="name" required class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.description') }}</span><textarea name="description" rows="3" class="crm-field"></textarea></label>
                    <x-ui.button type="submit" variant="secondary" class="w-full">{{ __('app.add') }}</x-ui.button>
                </form>
            </section>
        </aside>
    </div>
</x-festivals.staff.workspace>
@endsection
