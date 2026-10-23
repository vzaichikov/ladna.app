@extends('layouts.app')

@section('title', __('app.festival_sponsors').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_sponsors')" :copy="__('app.festival_sponsors_page_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.sponsors.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_sponsor_add') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition])" class="sm:grid-cols-2">
        <label>
            <span class="crm-label">{{ __('app.name') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}">
        </label>
        <label>
            <span class="crm-label">{{ __('app.festival_sponsor_placement') }}</span>
            <select name="visibility" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="all_pages" @selected($filters['visibility'] === 'all_pages')>{{ __('app.festival_sponsor_all_pages') }}</option>
                <option value="main_page" @selected($filters['visibility'] === 'main_page')>{{ __('app.festival_sponsor_main_page_only') }}</option>
            </select>
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse($sponsors as $sponsor)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                <div class="flex min-w-0 items-start gap-4">
                    <div class="flex h-20 w-24 shrink-0 items-center justify-center rounded-xl border border-stone-200 bg-white p-3">
                        <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}" class="max-h-full w-full object-contain" loading="lazy">
                    </div>
                    <div class="min-w-0">
                        <h2 class="break-words font-semibold text-slate-950">{{ $sponsor->name }}</h2>
                        <p class="mt-1 line-clamp-2 break-words text-sm leading-6 text-slate-600">{{ $sponsor->short_description }}</p>
                        <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $sponsor->show_on_all_pages ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100 text-stone-600' }}">
                            {{ $sponsor->show_on_all_pages ? __('app.festival_sponsor_all_pages') : __('app.festival_sponsor_main_page_only') }}
                        </span>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2">
                    <x-ui.action-button :href="route('dashboard.accounts.festivals.sponsors.edit', [$account, $edition, $sponsor])" icon="edit" :label="__('app.edit')" />
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.sponsors.destroy', [$account, $edition, $sponsor]) }}" data-confirm-delete data-confirm-title="{{ __('app.festival_sponsor_delete_title') }}" data-confirm-body="{{ __('app.festival_sponsor_delete_copy') }}">
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_sponsors_empty')" icon="handshake" class="m-5">
                @if($hasFilters)
                    <x-ui.button :href="route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition])" variant="secondary">{{ __('app.reset_filters') }}</x-ui.button>
                @else
                    {{ __('app.festival_sponsors_empty_copy') }}
                @endif
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>
    <div>{{ $sponsors->links() }}</div>
</x-festivals.staff.workspace>
@endsection
