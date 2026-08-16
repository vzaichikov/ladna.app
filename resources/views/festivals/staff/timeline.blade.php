@extends('layouts.app')

@section('title', __('app.festival_timeline_title').' · '.$stage->name.' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_timeline_title')" :copy="__('app.festival_timeline_copy')">
        @unless ($workspacePermissions['event_festival_staff'] ?? false)
            <x-slot:actions>
                <x-ui.button :href="route('dashboard.accounts.festivals.program', ['account' => $account, 'festivalEdition' => $edition, 'scene' => $stage->id])" variant="secondary">
                    <x-ui.icon name="calendar-days" class="h-4 w-4" />
                    {{ __('app.festival_tab_program') }}
                </x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.festivals.settings.stages', [$account, $edition])" variant="secondary">
                    <x-ui.icon name="settings" class="h-4 w-4" />
                    {{ __('app.festival_manage_scenes') }}
                </x-ui.button>
            </x-slot:actions>
        @endunless
    </x-ui.page-header>

    @include('festivals.staff._timeline-fragment')
</x-festivals.staff.workspace>
@endsection
