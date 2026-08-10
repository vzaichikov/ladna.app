@extends('layouts.app')

@section('title', __('app.festival_taxonomy_directions').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header><p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p><h1 class="crm-page-title mt-2">{{ __('app.festival_taxonomy_directions') }}</h1><p class="crm-page-copy">{{ __('app.festival_directions_page_copy') }}</p></header>
    <x-festivals.settings-help :title="__('app.festival_directions_help_title')" :description="__('app.festival_directions_help_copy')" :dependencies="[__('app.festival_taxonomy_directions'), __('app.festival_categories'), __('app.festival_entries')]" />
    <div class="space-y-5">@foreach($axes as $axis)<x-festivals.taxonomy-axis :$account :$edition :$axis kind-locked />@endforeach</div>
    @if($axes->isEmpty())
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_create_direction_group') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_create_direction_group_copy') }}</p>
            <form method="POST" action="{{ route('dashboard.accounts.festivals.axes.store', [$account, $edition]) }}" class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                @csrf
                <input type="hidden" name="kind" value="direction">
                <input type="hidden" name="is_required" value="1">
                <label>
                    <span class="crm-label">{{ __('app.name') }}</span>
                    <input name="name" value="{{ __('app.festival_direction_axis_default') }}" required class="crm-field">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_direction_group_name_help') }}</span>
                </label>
                <x-ui.button type="submit" class="self-end">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.create') }}
                </x-ui.button>
            </form>
        </section>
    @endif
</x-festivals.staff.workspace>
@endsection
