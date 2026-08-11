@extends('layouts.app')

@section('title', __('app.festival_judges').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_judges')" :copy="__('app.festival_judges_page_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.judging.judges.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_add_judge') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition])"
        class="sm:grid-cols-3"
    >
        <label>
            <span class="crm-label">{{ __('app.name') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}">
        </label>
        <label>
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option>
            </select>
        </label>
        <label>
            <span class="crm-label">{{ __('app.festival_category') }}</span>
            <select name="category_id" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['category_id'] === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($assignments as $assignment)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_minmax(180px,0.8fr)_150px_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate font-semibold text-slate-950">{{ $assignment->display_name }}</h2>
                        @if ($assignment->is_head_judge)
                            <span class="rounded-full bg-violet-crm-100 px-2.5 py-1 text-xs font-semibold text-brand-700">{{ __('app.festival_head_judge') }}</span>
                        @endif
                        @unless ($assignment->is_active)
                            <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>
                        @endunless
                    </div>
                    <p class="mt-1 truncate text-sm text-slate-500">
                        @if ($assignment->user)
                            {{ __('app.festival_staff_identity') }} · {{ $assignment->user->name }} · {{ $assignment->user->email }}
                        @else
                            {{ __('app.festival_guest_identity') }} · {{ $assignment->portalUser?->displayName() }} · {{ $assignment->portalUser?->email }}
                        @endif
                    </p>
                </div>
                <p class="text-sm text-slate-600">{{ $assignment->categories->pluck('name')->join(', ') }}</p>
                <p class="text-sm text-slate-500">{{ trans_choice('app.festival_score_sheet_usage_count', $assignment->score_sheets_count, ['count' => $assignment->score_sheets_count]) }}</p>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <x-ui.action-button :href="route('dashboard.accounts.festivals.judging.judges.edit', [$account, $edition, $assignment])" icon="edit" :label="__('app.edit')" />
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.judges.toggle', [$account, $edition, $assignment]) }}">
                        @csrf
                        @method('PATCH')
                        <x-ui.action-button
                            type="submit"
                            :variant="$assignment->is_active ? 'danger' : 'success'"
                            :icon="$assignment->is_active ? 'power' : 'circle-check'"
                            :label="$assignment->is_active ? __('app.deactivate') : __('app.activate')"
                        />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_judges_empty')" icon="users" class="m-5">
                @if ($hasFilters)
                    <x-ui.button :href="route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                @else
                    {{ __('app.festival_judges_empty_copy') }}
                @endif
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>

    <div>{{ $assignments->links() }}</div>
</x-festivals.staff.workspace>
@endsection
