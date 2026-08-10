@extends('layouts.app')

@section('title', __('app.festival_settings_overview').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p>
            <h1 class="crm-page-title mt-2">{{ __('app.festival_settings_overview') }}</h1>
            <p class="crm-page-copy">{{ __('app.festival_settings_overview_copy') }}</p>
        </div>
        @if($permissions['manage'])<x-ui.button :href="route('dashboard.accounts.festivals.edit', [$account, $edition])" variant="secondary">{{ __('app.festival_edit_edition_details') }}</x-ui.button>@endif
    </header>

    <x-festivals.settings-help :title="__('app.festival_settings_structure_title')" :description="__('app.festival_settings_structure_copy')" :dependencies="[__('app.festival_taxonomy_directions'), __('app.festival_classifications'), __('app.festival_categories'), __('app.festival_registration_workflows'), __('app.festival_requirements'), __('app.festival_fees')]" />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @php($cards = [
            ['directions', 'festival_taxonomy_directions', 'festival_directions_card_copy', $permissions['manage']],
            ['classifications', 'festival_classifications', 'festival_classifications_card_copy', $permissions['manage']],
            ['categories', 'festival_categories', 'festival_categories_card_copy', $permissions['manage']],
            ['workflows', 'festival_registration_workflows', 'festival_workflows_card_copy', $permissions['manage']],
            ['requirements', 'festival_requirements', 'festival_requirements_card_copy', $permissions['manage']],
            ['fees', 'festival_fees', 'festival_fees_card_copy', $permissions['finance']],
            ['content', 'festival_content_media', 'festival_content_card_copy', $permissions['manage']],
        ])
        @foreach($cards as [$page, $label, $copy, $visible])
            @continue(!$visible)
            <a href="{{ route('dashboard.accounts.festivals.settings.'.$page, [$account, $edition]) }}" class="group rounded-2xl border border-stone-200 bg-white p-5 shadow-crm transition hover:-translate-y-0.5 hover:border-brand-300">
                <div class="flex items-start justify-between gap-4"><h2 class="text-lg font-semibold text-slate-950">{{ __('app.'.$label) }}</h2><span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ $counts[$page] }}</span></div>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.'.$copy) }}</p>
                <span class="mt-4 inline-flex text-sm font-semibold text-brand-700 group-hover:text-brand-800">{{ __('app.open') }} →</span>
            </a>
        @endforeach
    </div>
</x-festivals.staff.workspace>
@endsection
