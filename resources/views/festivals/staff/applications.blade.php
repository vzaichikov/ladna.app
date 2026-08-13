@extends('layouts.app')

@section('title', __('app.festival_tab_applications').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_applications_title')" :copy="__('app.festival_applications_copy')" />

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
        @php
            $preservedApplicationFilters = array_filter([
                'q' => $filters['q'],
                'category' => $filters['category'],
            ], fn ($value) => $value !== '');
        @endphp

        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold">{{ __('app.festival_applications_title') }}</h2>
            <span class="text-sm font-semibold text-slate-500">{{ $entries->total() }}</span>
        </div>

        <div class="mt-5">
            <h3 id="festival-entry-status-cards" class="font-semibold text-slate-950">{{ __('app.festival_entries_by_status') }}</h3>
            <nav class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-labelledby="festival-entry-status-cards">
                <a
                    href="{{ route('dashboard.accounts.festivals.applications', array_merge([$account, $edition], $preservedApplicationFilters)) }}"
                    class="flex min-w-0 items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-900 transition hover:border-slate-300 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 {{ $filters['status'] === '' ? 'ring-2 ring-brand-500 ring-offset-2' : '' }}"
                    data-status-card="all"
                    @if($filters['status'] === '') aria-current="page" @endif
                >
                    <span class="truncate text-sm font-semibold">{{ __('app.all') }}</span>
                    <strong class="text-xl">{{ $entryStatistics->sum() }}</strong>
                </a>
                @foreach (\App\Enums\FestivalEntryStatus::cases() as $status)
                    @php
                        $statusCardClasses = match ($status) {
                            \App\Enums\FestivalEntryStatus::Draft => 'border-stone-200 bg-stone-50 text-stone-800 hover:border-stone-300 hover:bg-stone-100',
                            \App\Enums\FestivalEntryStatus::Submitted => 'border-sky-200 bg-sky-50 text-sky-900 hover:border-sky-300 hover:bg-sky-100',
                            \App\Enums\FestivalEntryStatus::UnderReview => 'border-amber-200 bg-amber-50 text-amber-900 hover:border-amber-300 hover:bg-amber-100',
                            \App\Enums\FestivalEntryStatus::ChangesPending => 'border-violet-200 bg-violet-50 text-violet-900 hover:border-violet-300 hover:bg-violet-100',
                            \App\Enums\FestivalEntryStatus::Accepted => 'border-emerald-200 bg-emerald-50 text-emerald-900 hover:border-emerald-300 hover:bg-emerald-100',
                            \App\Enums\FestivalEntryStatus::Rejected => 'border-rose-200 bg-rose-50 text-rose-900 hover:border-rose-300 hover:bg-rose-100',
                            \App\Enums\FestivalEntryStatus::Withdrawn => 'border-slate-300 bg-slate-100 text-slate-800 hover:border-slate-400 hover:bg-slate-200',
                        };
                    @endphp
                    <a
                        href="{{ route('dashboard.accounts.festivals.applications', array_merge([$account, $edition], $preservedApplicationFilters, ['status' => $status->value])) }}"
                        class="flex min-w-0 items-center justify-between gap-3 rounded-xl border px-4 py-3 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 {{ $statusCardClasses }} {{ $filters['status'] === $status->value ? 'ring-2 ring-brand-500 ring-offset-2' : '' }}"
                        data-status-card="{{ $status->value }}"
                        @if($filters['status'] === $status->value) aria-current="page" @endif
                    >
                        <span class="truncate text-sm font-semibold">{{ __('app.festival_entry_status_'.$status->value) }}</span>
                        <strong class="text-xl">{{ $entryStatistics[$status->value] ?? 0 }}</strong>
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="mt-5">
            <x-ui.filter-bar
                :action="route('dashboard.accounts.festivals.applications', [$account, $edition])"
                :reset-href="route('dashboard.accounts.festivals.applications', [$account, $edition])"
                class="sm:grid-cols-2 xl:grid-cols-3"
            >
                <label>
                    <span class="crm-label">{{ __('app.search') }}</span>
                    <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_entry_search_placeholder') }}">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.status') }}</span>
                    <select name="status" class="crm-field">
                        <option value="">{{ __('app.all') }}</option>
                        @foreach (\App\Enums\FestivalEntryStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ __('app.festival_entry_status_'.$status->value) }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_category') }}</span>
                    <select name="category" class="crm-field">
                        <option value="">{{ __('app.all') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
            </x-ui.filter-bar>
        </div>

        <div class="mt-5 space-y-3">
            @forelse ($entries as $entry)
                @php
                    $qualificationReady = in_array($entry->qualification_status, [\App\Enums\FestivalQualificationStatus::NotRequired, \App\Enums\FestivalQualificationStatus::Passed], true);
                    $entryIsReady = $entry->status === \App\Enums\FestivalEntryStatus::Accepted
                        && $qualificationReady
                        && $entry->blocking_requirements_count === 0
                        && $entry->blocking_charges_count === 0
                        && $entry->performance_slots_count > 0;
                @endphp
                <article class="rounded-xl border border-stone-200 bg-slate-50/70 p-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-semibold text-slate-500">{{ $entry->code }}</span>
                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_entry_status_'.$entry->status->value) }}</span>
                                <span class="{{ $entryIsReady ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }} rounded-full px-2.5 py-1 text-xs font-semibold">
                                    {{ $entryIsReady ? __('app.festival_ready') : __('app.festival_not_ready') }}
                                </span>
                            </div>
                            <h3 class="mt-2 truncate text-lg font-semibold text-slate-950">{{ $entry->entry_name }}</h3>
                            <p class="text-sm text-slate-500">{{ $entry->category->direction->name }} · {{ $entry->category->name }}</p>
                            @if ($workspacePermissions['registrations'])
                                <p class="mt-1 text-sm text-slate-600">{{ $entry->portalUser->displayName() }} · {{ $entry->portalUser->email }}</p>
                            @endif
                        </div>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="grid grid-cols-3 gap-2 text-center text-xs sm:min-w-72">
                                <div class="rounded-lg bg-white px-3 py-2"><strong class="block text-base">{{ $entry->blocking_requirements_count }}</strong>{{ __('app.festival_requirements_open') }}</div>
                                <div class="rounded-lg bg-white px-3 py-2"><strong class="block text-base">{{ $entry->blocking_charges_count }}</strong>{{ __('app.festival_charges_open') }}</div>
                                <div class="rounded-lg bg-white px-3 py-2"><strong class="block text-base">{{ $entry->performance_slots_count }}</strong>{{ __('app.festival_program_slots') }}</div>
                            </div>
                            <x-ui.button :href="route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry])">
                                <x-ui.icon name="edit" class="h-4 w-4" />{{ __('app.festival_open_application') }}
                            </x-ui.button>
                        </div>
                    </div>
                </article>
            @empty
                <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_applications_empty')" icon="accounts">
                    @if ($hasFilters)
                        <x-ui.button :href="route('dashboard.accounts.festivals.applications', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                    @endif
                </x-ui.empty-state>
            @endforelse
        </div>

        <div class="mt-5">{{ $entries->links() }}</div>
    </section>
</x-festivals.staff.workspace>
@endsection
