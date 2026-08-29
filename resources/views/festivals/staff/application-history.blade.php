@extends('layouts.app')

@section('title', __('app.festival_application_history').' - '.$entry->entry_name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_application_history')" :copy="$entry->entry_name.' · '.$entry->code">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry])" variant="secondary">
                <x-ui.icon name="file-text" class="h-4 w-4" />{{ __('app.festival_application_tab_details') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.festivals.applications', [$account, $edition])" variant="secondary">{{ __('app.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <section aria-labelledby="festival-application-history-title">
        <div>
            <h2 id="festival-application-history-title" class="text-xl font-semibold text-slate-950">{{ __('app.festival_application_history') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_application_history_copy') }}</p>
        </div>

        <div class="mt-5 rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <x-ui.filter-bar
                :action="route('dashboard.accounts.festivals.applications.history', [$account, $edition, $entry])"
                :reset-href="route('dashboard.accounts.festivals.applications.history', [$account, $edition, $entry])"
            >
                <label class="block min-w-0">
                    <span class="crm-label">{{ __('app.festival_history_type') }}</span>
                    <select name="type" class="crm-field min-h-11">
                        <option value="">{{ __('app.all') }}</option>
                        @foreach ($historyTypeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($historyType === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </x-ui.filter-bar>
        </div>

        <div class="mt-5 space-y-4">
            @forelse ($activityHistory as $activity)
                @php
                    [$historyCardClass, $historyBadgeClass] = match ($activity['type']) {
                        \App\Support\Festivals\FestivalApplicationHistoryTypes::Lifecycle => ['border-violet-200 bg-violet-50/40', 'bg-violet-100 text-violet-800'],
                        \App\Support\Festivals\FestivalApplicationHistoryTypes::Reviews => ['border-emerald-200 bg-emerald-50/40', 'bg-emerald-100 text-emerald-800'],
                        \App\Support\Festivals\FestivalApplicationHistoryTypes::Fields => ['border-sky-200 bg-sky-50/40', 'bg-sky-100 text-sky-800'],
                        \App\Support\Festivals\FestivalApplicationHistoryTypes::Payments => ['border-amber-200 bg-amber-50/40', 'bg-amber-100 text-amber-900'],
                        \App\Support\Festivals\FestivalApplicationHistoryTypes::ProgramResults => ['border-rose-200 bg-rose-50/40', 'bg-rose-100 text-rose-800'],
                        default => ['border-slate-200 bg-slate-50/60', 'bg-slate-200 text-slate-800'],
                    };
                @endphp
                <article class="rounded-2xl border p-5 shadow-crm {{ $historyCardClass }}" data-history-type="{{ $activity['type'] }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-slate-950">{{ $activity['title'] }}</h3>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $historyBadgeClass }}">{{ $activity['type_label'] }}</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_activity_by', ['actor' => $activity['actor']]) }}</p>
                        </div>
                        <time class="shrink-0 text-xs text-slate-500" datetime="{{ $activity['occurred_at']->toAtomString() }}">{{ $activity['occurred_at']->timezone($edition->timezone)->format('d.m.Y H:i') }}</time>
                    </div>
                    @if ($activity['details'] !== [])
                        <ul class="mt-4 space-y-2 text-sm text-slate-700">
                            @foreach ($activity['details'] as $detail)
                                <li class="whitespace-pre-line break-words rounded-xl bg-white/80 px-3 py-2">{{ $detail }}</li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @empty
                <x-ui.empty-state icon="history">{{ __('app.festival_application_history_empty') }}</x-ui.empty-state>
            @endforelse
        </div>
        <div class="mt-5">{{ $activityHistory->links() }}</div>
    </section>
</x-festivals.staff.workspace>
@endsection
