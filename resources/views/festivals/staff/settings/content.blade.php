@extends('layouts.app')

@section('title', __('app.festival_content_media').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_content_media')" :copy="__('app.festival_content_page_copy')" />

    <x-festivals.settings-help
        :title="__('app.festival_content_help_title')"
        :description="__('app.festival_content_help_copy')"
        :dependencies="[__('app.festival_content_sections'), __('app.festival_documents'), __('app.festival_media'), __('app.festival_public_page')]"
    />

    @php($cards = [
        ['sections', 'festival_content_sections', 'festival_content_sections_copy', 'file-text'],
        ['documents', 'festival_documents', 'festival_documents_crud_copy', 'files'],
        ['media', 'festival_media', 'festival_media_crud_copy', 'images'],
    ])
    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($cards as [$page, $label, $copy, $icon])
            <a href="{{ route('dashboard.accounts.festivals.settings.content.'.$page, [$account, $edition]) }}" class="group rounded-xl border border-stone-200 bg-white p-5 shadow-crm transition hover:-translate-y-0.5 hover:border-brand-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                <div class="flex items-start justify-between gap-4">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700"><x-ui.icon :name="$icon" class="h-5 w-5" /></span>
                    <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ $counts[$page] }}</span>
                </div>
                <h2 class="mt-4 text-lg font-semibold text-slate-950">{{ __('app.'.$label) }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.'.$copy) }}</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-brand-700 group-hover:text-brand-800">{{ __('app.open') }} →</span>
            </a>
        @endforeach
    </div>
</x-festivals.staff.workspace>
@endsection
