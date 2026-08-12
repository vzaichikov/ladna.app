@extends('layouts.app')

@section('title', __('app.archive').' '.$participant->displayName().' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_archive_participant')" :copy="$participant->displayName()" />
    <x-ui.panel class="max-w-2xl">
        <p class="leading-7 text-slate-600">{{ __('app.festival_archive_participant_copy', ['count' => $participant->entries_count]) }}</p>
        <form method="POST" action="{{ route('dashboard.accounts.festivals.users.participants.destroy', [$account, $edition, $portalUser, $participant]) }}" class="mt-6 flex flex-wrap gap-2">
            @csrf
            @method('PATCH')
            <x-ui.button type="submit" variant="danger">{{ __('app.archive') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
