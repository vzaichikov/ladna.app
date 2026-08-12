@extends('layouts.app')

@section('title', __('app.festival_content_sections').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_content_sections')" :copy="__('app.festival_content_sections_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.content.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_content_section') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.settings.content.sections', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.settings.content.sections', [$account, $edition])" class="sm:grid-cols-3">
        <label><span class="crm-label">{{ __('app.name') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
        <label><span class="crm-label">{{ __('app.visibility') }}</span><select name="visibility" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($visibilities as $visibility)<option value="{{ $visibility }}" @selected($filters['visibility'] === $visibility)>{{ __('app.festival_visibility_'.$visibility) }}</option>@endforeach</select></label>
    </x-ui.filter-bar>

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($sections as $section)
            @php($globalIndex = ($sections->firstItem() ?? 1) + $loop->index)
            <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h2 class="truncate font-semibold text-slate-950" title="{{ $section->title }}">{{ $section->title }}</h2>@unless ($section->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless</div><p class="mt-1 text-sm text-slate-500">{{ __('app.festival_visibility_'.$section->visibility) }}</p></div>
                    <x-festivals.settings-actions
                        :active="$section->is_active"
                        :toggle-route="route('dashboard.accounts.festivals.content.toggle', [$account, $edition, $section])"
                        :move-route="route('dashboard.accounts.festivals.content.move', [$account, $edition, $section])"
                        :edit-route="route('dashboard.accounts.festivals.content.edit', [$account, $edition, $section])"
                        :delete-route="route('dashboard.accounts.festivals.content.destroy', [$account, $edition, $section])"
                        :delete-label="__('app.festival_delete_content_section')"
                        :delete-confirm-title="__('app.festival_delete_content_section_title')"
                        :delete-confirm-body="__('app.festival_delete_content_section_copy')"
                        :show-ordering="! $hasFilters"
                        :can-move-up="$globalIndex > 1"
                        :can-move-down="$globalIndex < $sections->total()"
                    />
                </div>
                @if (filled(strip_tags($section->body_html ?? '')))<p class="mt-4 line-clamp-3 text-sm leading-6 text-slate-600">{{ strip_tags($section->body_html) }}</p>@endif
            </article>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_content_sections_empty')" icon="file-text" class="lg:col-span-2">{{ $hasFilters ? __('app.festival_filtered_empty_copy') : __('app.festival_content_sections_empty_copy') }}</x-ui.empty-state>
        @endforelse
    </div>
    <div>{{ $sections->links() }}</div>
</x-festivals.staff.workspace>
@endsection
