@extends('layouts.app')

@section('title', __('app.festival_media').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_media')" :copy="__('app.festival_media_crud_copy')">
        <x-slot:actions><x-ui.button :href="route('dashboard.accounts.festivals.media.create', [$account, $edition])"><x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_media') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>
    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.settings.content.media', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.settings.content.media', [$account, $edition])" class="sm:grid-cols-2 xl:grid-cols-4">
        <label><span class="crm-label">{{ __('app.name') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_media_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
        <label><span class="crm-label">{{ __('app.type') }}</span><select name="kind" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($kinds as $kind)<option value="{{ $kind }}" @selected($filters['kind'] === $kind)>{{ __('app.festival_media_kind_'.$kind) }}</option>@endforeach</select></label>
        <label><span class="crm-label">{{ __('app.festival_cover_state') }}</span><select name="cover" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="cover" @selected($filters['cover'] === 'cover')>{{ __('app.festival_cover_only') }}</option><option value="regular" @selected($filters['cover'] === 'regular')>{{ __('app.festival_without_cover') }}</option></select></label>
    </x-ui.filter-bar>
    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($mediaItems as $media)
            @php($globalIndex = ($mediaItems->firstItem() ?? 1) + $loop->index)
            <article class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-crm">
                <div class="aspect-[16/7] bg-stone-100">@if ($media->kind === 'image')<img src="{{ $media->external_url }}" alt="{{ $media->alt_text ?? '' }}" class="h-full w-full object-cover" loading="lazy">@else<div class="flex h-full items-center justify-center text-brand-700"><x-ui.icon name="video" class="h-10 w-10" /></div>@endif</div>
                <div class="p-5"><div class="flex items-start justify-between gap-4"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h2 class="font-semibold text-slate-950">{{ __('app.festival_media_kind_'.$media->kind) }}</h2>@if ($media->is_cover)<span class="rounded-full bg-violet-crm-100 px-2.5 py-1 text-xs font-semibold text-brand-700">{{ __('app.festival_cover') }}</span>@endif @unless ($media->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless</div><p class="mt-1 truncate text-sm text-slate-500" title="{{ $media->caption ?: $media->external_url }}">{{ $media->caption ?: $media->external_url }}</p></div><x-festivals.settings-actions :active="$media->is_active" :toggle-route="route('dashboard.accounts.festivals.media.toggle', [$account, $edition, $media])" :move-route="route('dashboard.accounts.festivals.media.move', [$account, $edition, $media])" :edit-route="route('dashboard.accounts.festivals.media.edit', [$account, $edition, $media])" :show-ordering="! $hasFilters" :can-move-up="$globalIndex > 1" :can-move-down="$globalIndex < $mediaItems->total()" /></div></div>
            </article>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_media_empty')" icon="images" class="lg:col-span-2">{{ $hasFilters ? __('app.festival_filtered_empty_copy') : __('app.festival_media_empty_copy') }}</x-ui.empty-state>
        @endforelse
    </div>
    <div>{{ $mediaItems->links() }}</div>
</x-festivals.staff.workspace>
@endsection
