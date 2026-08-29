@extends('layouts.app')

@section('title', __('app.festival_nominations').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_nominations')" :copy="__('app.festival_nominations_page_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.nominations.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_add_nomination') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.settings.nominations', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.settings.nominations', [$account, $edition])" class="sm:grid-cols-3">
        <label><span class="crm-label">{{ __('app.name') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
        <label><span class="crm-label">{{ __('app.festival_mini_app_visibility') }}</span><select name="visibility" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="shown" @selected($filters['visibility'] === 'shown')>{{ __('app.festival_shown') }}</option><option value="hidden" @selected($filters['visibility'] === 'hidden')>{{ __('app.festival_hidden') }}</option></select></label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse($nominations as $nomination)
            @php
                $globalIndex = ($nominations->firstItem() ?? 1) + $loop->index;
            @endphp
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_170px_minmax(18rem,auto)] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate font-semibold text-slate-950">{{ $nomination->name }}</h2>
                        @unless($nomination->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $nomination->show_in_mini_app ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100 text-stone-600' }}">{{ $nomination->show_in_mini_app ? __('app.festival_shown_in_mini_app') : __('app.festival_hidden_in_mini_app') }}</span>
                    </div>
                    @if($nomination->presented_by)<p class="mt-1 text-sm text-slate-500">{{ __('app.festival_nomination_presented_by') }}: {{ $nomination->presented_by }}</p>@endif
                </div>
                <p class="text-sm text-slate-500">{{ trans_choice('app.festival_nomination_assigned_count', $nomination->participants_count, ['count' => $nomination->participants_count]) }}</p>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.nominations.toggle', [$account, $edition, $nomination]) }}">@csrf @method('PATCH')<input type="hidden" name="field" value="show_in_mini_app"><x-ui.action-button type="submit" :variant="$nomination->show_in_mini_app ? 'danger' : 'success'" icon="smartphone" :label="$nomination->show_in_mini_app ? __('app.festival_hide_from_mini_app') : __('app.festival_show_in_mini_app')" /></form>
                    <x-festivals.settings-actions
                        :active="$nomination->is_active"
                        :toggle-route="route('dashboard.accounts.festivals.nominations.toggle', [$account, $edition, $nomination])"
                        :move-route="route('dashboard.accounts.festivals.nominations.move', [$account, $edition, $nomination])"
                        :edit-route="route('dashboard.accounts.festivals.nominations.edit', [$account, $edition, $nomination])"
                        :delete-route="route('dashboard.accounts.festivals.nominations.destroy', [$account, $edition, $nomination])"
                        :delete-confirm-title="__('app.festival_delete_nomination')"
                        :delete-confirm-body="__('app.festival_delete_nomination_copy')"
                        :show-ordering="! $hasFilters"
                        :can-move-up="$globalIndex > 1"
                        :can-move-down="$globalIndex < $nominations->total()"
                    />
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_nominations_empty')" icon="award" class="m-5">{{ $hasFilters ? '' : __('app.festival_nominations_empty_copy') }}</x-ui.empty-state>
        @endforelse
    </x-ui.panel>
    <div>{{ $nominations->links() }}</div>
</x-festivals.staff.workspace>
@endsection
