@extends('layouts.app')

@section('title', __('app.festival_communication_tab_history').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_communication_tab_history')" :copy="__('app.festival_notification_history_copy')" />

    @include('festivals.staff._communication-navigation')

    <section aria-labelledby="festival-queue-stats-title">
        <h2 id="festival-queue-stats-title" class="text-xl font-semibold text-slate-950">{{ __('app.festival_notification_queue_stats') }}</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach (\App\Enums\FestivalNotificationStatus::cases() as $status)
                @php($statusClass = match($status) { \App\Enums\FestivalNotificationStatus::Pending => 'border-amber-200 bg-amber-50 text-amber-900', \App\Enums\FestivalNotificationStatus::WaitingForSmsCredit => 'border-orange-200 bg-orange-50 text-orange-900', \App\Enums\FestivalNotificationStatus::Sending => 'border-sky-200 bg-sky-50 text-sky-900', \App\Enums\FestivalNotificationStatus::Sent => 'border-emerald-200 bg-emerald-50 text-emerald-900', \App\Enums\FestivalNotificationStatus::Failed => 'border-rose-200 bg-rose-50 text-rose-900', \App\Enums\FestivalNotificationStatus::Cancelled => 'border-stone-200 bg-stone-100 text-stone-800' })
                <div class="rounded-2xl border p-4 {{ $statusClass }}"><span class="text-sm font-medium">{{ __('app.festival_notification_status_'.$status->value) }}</span><strong class="mt-1 block text-2xl">{{ $notificationStatistics[$status->value] ?? 0 }}</strong></div>
            @endforeach
        </div>
    </section>

    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.communication.history', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.communication.history', [$account, $edition])" class="lg:grid-cols-4">
        <label><span class="crm-label">{{ __('app.search') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_notification_search_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.type') }}</span><select name="type" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach($notificationTypes as $type)<option value="{{ $type->value }}" @selected($filters['type'] === $type->value)>{{ __('app.festival_notification_type_'.$type->value) }}</option>@endforeach</select></label>
        <label><span class="crm-label">{{ __('app.channel') }}</span><select name="channel" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalNotificationChannel::cases() as $channel)<option value="{{ $channel->value }}" @selected($filters['channel'] === $channel->value)>{{ __('app.festival_notification_channel_'.$channel->value) }}</option>@endforeach</select></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalNotificationStatus::cases() as $status)<option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ __('app.festival_notification_status_'.$status->value) }}</option>@endforeach</select></label>
    </x-ui.filter-bar>

    <div class="space-y-4">
        @forelse ($notifications as $notification)
            <x-festivals.staff.notification-card :$notification :timezone="$edition->timezone" />
        @empty
            <x-ui.empty-state icon="bell">{{ __('app.festival_notifications_empty') }}</x-ui.empty-state>
        @endforelse
    </div>
    <div>{{ $notifications->links() }}</div>
</x-festivals.staff.workspace>
@endsection
