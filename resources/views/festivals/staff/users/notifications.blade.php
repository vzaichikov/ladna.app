@extends('layouts.app')

@section('title', __('app.festival_participant_edit_tab_notifications').' - '.$portalUser->displayName().' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_participant_notifications_history')" :copy="$portalUser->displayName()" />

    @include('festivals.staff.users._detail-nav', ['activeDetailPage' => 'notifications'])

    <section aria-labelledby="festival-participant-notifications-title">
        <div>
            <h2 id="festival-participant-notifications-title" class="text-xl font-semibold text-slate-950">{{ __('app.festival_participant_notifications_history') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_participant_notifications_history_copy') }}</p>
        </div>

        <div class="mt-5 space-y-4">
            @forelse ($festivalNotifications as $notification)
                <x-festivals.staff.notification-card
                    :$notification
                    :timezone="$notification->edition?->timezone ?? $edition->timezone"
                    :show-recipient="false"
                    :show-context="true"
                />
            @empty
                <x-ui.empty-state icon="bell">{{ __('app.festival_participant_notifications_empty') }}</x-ui.empty-state>
            @endforelse
        </div>
        <div>{{ $festivalNotifications->links() }}</div>
    </section>
</x-festivals.staff.workspace>
@endsection
