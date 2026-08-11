@extends('layouts.app')

@section('title', __('app.festival_documents').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_documents')" :copy="__('app.festival_documents_crud_copy')">
        <x-slot:actions><x-ui.button :href="route('dashboard.accounts.festivals.documents.create', [$account, $edition])"><x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_document') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>
    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.settings.content.documents', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.settings.content.documents', [$account, $edition])" class="sm:grid-cols-2 xl:grid-cols-4">
        <label><span class="crm-label">{{ __('app.name') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_document_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
        <label><span class="crm-label">{{ __('app.type') }}</span><select name="kind" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($kinds as $kind)<option value="{{ $kind }}" @selected($filters['kind'] === $kind)>{{ __('app.festival_document_kind_'.$kind) }}</option>@endforeach</select></label>
        <label><span class="crm-label">{{ __('app.visibility') }}</span><select name="visibility" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($visibilities as $visibility)<option value="{{ $visibility }}" @selected($filters['visibility'] === $visibility)>{{ __('app.festival_visibility_'.$visibility) }}</option>@endforeach</select></label>
    </x-ui.filter-bar>
    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($documents as $document)
            @php($globalIndex = ($documents->firstItem() ?? 1) + $loop->index)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-center">
                <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h2 class="truncate font-semibold text-slate-950" title="{{ $document->title }}">{{ $document->title }}</h2>@unless ($document->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless</div><p class="mt-1 truncate text-sm text-slate-500" title="{{ $document->original_name }}">{{ $document->original_name }}</p></div>
                <p class="text-sm text-slate-500">{{ __('app.festival_document_kind_'.$document->kind) }} · {{ __('app.festival_visibility_'.$document->visibility) }}</p>
                <x-festivals.settings-actions :active="$document->is_active" :toggle-route="route('dashboard.accounts.festivals.documents.toggle', [$account, $edition, $document])" :move-route="route('dashboard.accounts.festivals.documents.move', [$account, $edition, $document])" :edit-route="route('dashboard.accounts.festivals.documents.edit', [$account, $edition, $document])" :show-ordering="! $hasFilters" :can-move-up="$globalIndex > 1" :can-move-down="$globalIndex < $documents->total()" />
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_documents_empty')" icon="files" class="m-5">{{ $hasFilters ? __('app.festival_filtered_empty_copy') : __('app.festival_documents_empty_copy') }}</x-ui.empty-state>
        @endforelse
    </x-ui.panel>
    <div>{{ $documents->links() }}</div>
</x-festivals.staff.workspace>
@endsection
