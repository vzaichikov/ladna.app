@extends('layouts.app')

@section('title', __('app.festival_users').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_users')" :copy="__('app.festival_users_page_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.users.create', [$account, $edition, $tab === 'judges' ? 'judge' : 'registrant'])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ $tab === 'judges' ? __('app.festival_add_judge_profile') : __('app.festival_add_registrant') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <nav class="flex flex-wrap gap-2" aria-label="{{ __('app.festival_users') }}">
        @if (in_array('participants', $allowedTabs, true))
            <a href="{{ route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => 'participants']) }}" class="crm-tab" @if($tab === 'participants') aria-current="page" @endif>{{ __('app.festival_user_tab_participants') }}</a>
        @endif
        @if (in_array('judges', $allowedTabs, true))
            <a href="{{ route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => 'judges']) }}" class="crm-tab" @if($tab === 'judges') aria-current="page" @endif>{{ __('app.festival_user_tab_judges') }}</a>
        @endif
    </nav>

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.users.index', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => $tab])"
        class="sm:grid-cols-2"
    >
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label>
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_user_search_placeholder') }}">
        </label>
        <label>
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option>
            </select>
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($portalUsers as $portalUser)
            <article class="crm-row lg:grid-cols-[minmax(0,1fr)_minmax(220px,0.7fr)_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate font-semibold text-slate-950">{{ $portalUser->displayName() }}</h2>
                        <span class="{{ $portalUser->is_active ? 'crm-status-active' : 'crm-status-muted' }}">{{ $portalUser->is_active ? __('app.active') : __('app.inactive') }}</span>
                    </div>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
                        @if ($portalUser->email)<span>{{ $portalUser->email }}</span>@endif
                        @if ($portalUser->phone)<span>{{ $portalUser->phone }}</span>@endif
                        @if ($portalUser->studio_name)<span>{{ $portalUser->studio_name }}</span>@endif
                    </div>
                </div>
                <div class="text-sm text-slate-600">
                    @if ($tab === 'participants')
                        <p>{{ trans_choice('app.festival_roster_count', $portalUser->participants_count, ['count' => $portalUser->participants_count]) }}</p>
                        <p class="mt-1">{{ trans_choice('app.festival_current_entries_count', $portalUser->current_edition_entries_count, ['count' => $portalUser->current_edition_entries_count]) }}</p>
                    @else
                        <p>{{ $portalUser->current_edition_assignments_count > 0 ? __('app.festival_judge_assigned_current') : __('app.festival_judge_not_assigned_current') }}</p>
                        @if ($portalUser->is_active && $portalUser->current_edition_assignments_count === 0)
                            <a href="{{ route('dashboard.accounts.festivals.judging.judges.create', [$account, $edition, 'festival_portal_user_id' => $portalUser->id]) }}" class="mt-1 inline-flex font-semibold text-brand-700 hover:text-brand-600">{{ __('app.festival_assign_to_edition') }}</a>
                        @endif
                    @endif
                </div>
                <div class="flex justify-end">
                    <x-ui.action-button
                        :href="route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser])"
                        :label="__('app.edit')"
                    />
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_users_empty')" icon="users" class="m-5">
                @if ($hasFilters)
                    <x-ui.button :href="route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => $tab])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                @endif
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>

    <div>{{ $portalUsers->links() }}</div>
</x-festivals.staff.workspace>
@endsection
