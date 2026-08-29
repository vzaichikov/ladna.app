@php
    $indexRoute = $guest
        ? route('festival.portal.judging.results.nominations.index', [$account->slug, $edition])
        : route('dashboard.accounts.festivals.judging.results.nominations.index', [$account, $edition]);
    $updateRoute = fn($nomination) => $guest
        ? route('festival.portal.judging.results.nominations.update', [$account->slug, $edition, $nomination])
        : route('dashboard.accounts.festivals.judging.results.nominations.update', [$account, $edition, $nomination]);
@endphp

<div class="space-y-6" data-festival-nomination-assignments>
    @if(session('status'))<div class="rounded-xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-xl bg-rose-50 p-4 text-sm font-semibold text-rose-800">{{ $errors->first() }}</div>@endif
    @unless($editable)<div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">{{ __('app.festival_nomination_assignments_read_only') }}</div>@endunless

    <x-ui.filter-bar :action="$indexRoute" :reset-href="$indexRoute">
        <label><span class="crm-label">{{ __('app.festival_participant') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_participant_search_placeholder') }}"></label>
    </x-ui.filter-bar>

    @if($filters['q'] !== '')
        <x-ui.panel padding="none" class="overflow-hidden">
            @forelse($participantRows as $participant)
                <div class="crm-row lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                    <div><p class="font-semibold text-slate-950">{{ $participant->displayName() }}</p><p class="mt-1 text-xs text-slate-500">{{ $participant->entries->pluck('category.name')->unique()->join(', ') }}</p></div>
                    <div class="flex flex-wrap gap-2">
                        @foreach($nominations as $nomination)
                            @php
                                $assigned = $participant->nominations->contains('id', $nomination->id);
                            @endphp
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $assigned ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100 text-stone-500' }}">{{ $nomination->name }} · {{ $assigned ? __('app.festival_assigned') : __('app.festival_not_assigned') }}</span>
                        @endforeach
                    </div>
                </div>
            @empty
                <x-ui.empty-state :title="__('app.no_data')" icon="search" class="m-5" />
            @endforelse
        </x-ui.panel>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse($nominations as $nomination)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2"><h2 class="text-xl font-semibold text-slate-950">{{ $nomination->name }}</h2>@unless($nomination->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless</div>
                        @if($nomination->presented_by)<p class="mt-1 text-sm text-slate-500">{{ __('app.festival_nomination_presented_by') }}: {{ $nomination->presented_by }}</p>@endif
                        @if($nomination->prize)<p class="mt-1 text-sm text-slate-500">{{ __('app.festival_nomination_prize') }}: {{ $nomination->prize }}</p>@endif
                    </div>
                    <span class="rounded-full bg-violet-crm-50 px-2.5 py-1 text-xs font-semibold text-violet-crm-700">{{ trans_choice('app.festival_nomination_assigned_count', $nomination->participants->count(), ['count' => $nomination->participants->count()]) }}</span>
                </div>
                <div class="mt-4 flex min-h-12 flex-wrap content-start gap-2">
                    @forelse($nomination->participants as $participant)<span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ $participant->displayName() }}</span>@empty<span class="text-sm text-slate-500">{{ __('app.festival_nomination_nobody_assigned') }}</span>@endforelse
                </div>
                @if($editable && $nomination->is_active)<x-ui.button type="button" size="sm" class="mt-4" data-nomination-modal-open="nomination-modal-{{ $nomination->id }}"><x-ui.icon name="users" class="h-4 w-4" />{{ __('app.festival_assign_nomination') }}</x-ui.button>@endif
            </article>

            @if($editable && $nomination->is_active)
                <div id="nomination-modal-{{ $nomination->id }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-4" role="dialog" aria-modal="true" aria-labelledby="nomination-modal-title-{{ $nomination->id }}" data-nomination-modal>
                    <div class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                        <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4"><div><p class="text-xs font-bold uppercase tracking-wide text-violet-crm-700">{{ __('app.festival_nomination') }}</p><h2 id="nomination-modal-title-{{ $nomination->id }}" class="text-xl font-semibold text-slate-950">{{ $nomination->name }}</h2></div><button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-stone-100" data-nomination-modal-close aria-label="{{ __('app.close') }}"><x-ui.icon name="x" class="h-5 w-5" /></button></div>
                        <div class="grid gap-3 border-b border-stone-200 bg-stone-50 p-4 sm:grid-cols-2"><label><span class="crm-label">{{ __('app.name') }}</span><input class="crm-field" data-nomination-participant-search placeholder="{{ __('app.festival_participant_search_placeholder') }}"></label><label><span class="crm-label">{{ __('app.festival_category') }}</span><select class="crm-field" data-nomination-category-filter><option value="">{{ __('app.all') }}</option>@foreach($categories as $filterCategory)<option value="{{ $filterCategory->id }}">{{ $filterCategory->name }}</option>@endforeach</select></label></div>
                        <form method="POST" action="{{ $updateRoute($nomination) }}" data-async-form data-async-success="reload" class="flex min-h-0 flex-1 flex-col">@csrf @method('PUT')
                            <input type="hidden" name="participant_ids_present" value="1">
                            <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
                                @foreach($participants as $participant)
                                    <label class="flex items-start gap-3 rounded-xl border border-stone-200 p-3 hover:bg-violet-crm-50/40" data-nomination-participant-candidate data-name="{{ mb_strtolower($participant->displayName()) }}" data-category-ids="{{ $participant->entries->pluck('festival_category_id')->unique()->join(',') }}">
                                        <input type="checkbox" name="participant_ids[]" value="{{ $participant->id }}" class="mt-1" @checked($nomination->participants->contains('id', $participant->id))>
                                        <span><span class="block font-semibold text-slate-950">{{ $participant->displayName() }}</span><span class="mt-1 block text-xs text-slate-500">{{ $participant->entries->pluck('category.name')->unique()->join(', ') }}</span></span>
                                    </label>
                                @endforeach
                                <p class="hidden py-8 text-center text-sm text-slate-500" data-nomination-filter-empty>{{ __('app.no_data') }}</p>
                            </div>
                            <div class="flex items-center justify-end gap-2 border-t border-stone-200 px-5 py-4"><button type="button" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-stone-100" data-nomination-modal-close>{{ __('app.cancel') }}</button><x-ui.button type="submit">{{ __('app.save') }}</x-ui.button></div>
                        </form>
                    </div>
                </div>
            @endif
        @empty
            <x-ui.empty-state :title="__('app.festival_nominations_empty')" icon="award" class="lg:col-span-2">{{ __('app.festival_nominations_empty_copy') }}</x-ui.empty-state>
        @endforelse
    </div>
</div>
