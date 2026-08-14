@extends('layouts.app')

@section('title', __('app.festival_application').' - '.$entry->entry_name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_application')" :copy="$entry->entry_name.' · '.$entry->code">
        <x-slot:actions>
            @if ($canDeleteApplication)
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.festivals.applications.destroy', [$account, $edition, $entry]) }}"
                    data-confirm-delete
                    data-confirm-title="{{ __('app.festival_delete_application_title') }}"
                    data-confirm-body="{{ __('app.festival_delete_application_copy') }}"
                    data-confirm-accept="{{ __('app.festival_delete_application') }}"
                >
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="trash" class="h-4 w-4" />{{ __('app.festival_delete_application') }}
                    </x-ui.button>
                </form>
            @endif
            @if ($tab === 'details' && $entry->status === \App\Enums\FestivalEntryStatus::Accepted && $workspacePermissions['registrations'])
                <x-ui.button :href="route('dashboard.accounts.festivals.performances.show', [$account, $edition, $entry])" variant="secondary">
                    <x-ui.icon name="eye" class="h-4 w-4" />{{ __('app.festival_readonly_summary') }}
                </x-ui.button>
            @endif
            <x-ui.button :href="route('dashboard.accounts.festivals.applications', [$account, $edition])" variant="secondary">{{ __('app.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @error('festival_application')
        <div class="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $message }}</div>
    @enderror

    @if ($workspacePermissions['registrations'])
        <nav class="flex gap-1 overflow-x-auto rounded-2xl bg-slate-100 p-1" aria-label="{{ __('app.festival_application_tabs') }}">
            @foreach (['details', 'history'] as $applicationTab)
                <a href="{{ route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry, 'tab' => $applicationTab]) }}" class="whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-semibold {{ $tab === $applicationTab ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-950' }}" @if($tab === $applicationTab) aria-current="page" @endif>{{ __('app.festival_application_tab_'.$applicationTab) }}</a>
            @endforeach
        </nav>
    @endif

    @if ($tab === 'history')
        <section aria-labelledby="festival-application-history-title">
            <div>
                <h2 id="festival-application-history-title" class="text-xl font-semibold text-slate-950">{{ __('app.festival_application_history') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_application_history_copy') }}</p>
            </div>

            <div class="mt-5 space-y-4">
                @forelse ($activityHistory as $activity)
                    <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="font-semibold text-slate-950">{{ $activity['title'] }}</h3>
                                <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_activity_by', ['actor' => $activity['actor']]) }}</p>
                            </div>
                            <time class="shrink-0 text-xs text-slate-500" datetime="{{ $activity['occurred_at']->toAtomString() }}">{{ $activity['occurred_at']->timezone($edition->timezone)->format('d.m.Y H:i') }}</time>
                        </div>
                        @if ($activity['details'] !== [])
                            <ul class="mt-4 space-y-2 text-sm text-slate-700">
                                @foreach ($activity['details'] as $detail)
                                    <li class="whitespace-pre-line break-words rounded-xl bg-slate-50 px-3 py-2">{{ $detail }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </article>
                @empty
                    <x-ui.empty-state icon="history">{{ __('app.festival_application_history_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
            <div>{{ $activityHistory->links() }}</div>
        </section>
    @else
        <section class="rounded-2xl border border-stone-200 bg-slate-50/70 p-5 shadow-crm">
            @include('festivals.staff._application-review')
        </section>
    @endif
</x-festivals.staff.workspace>
@endsection
