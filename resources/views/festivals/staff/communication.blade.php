@extends('layouts.app')

@section('title', __('app.festival_tab_communication').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_communication_title')" :copy="__('app.festival_communication_copy')" />

    <nav class="flex gap-1 overflow-x-auto rounded-2xl bg-slate-100 p-1" aria-label="{{ __('app.festival_communication_tabs') }}">
        @foreach (['history', 'announcements', 'settings'] as $communicationTab)
            <a href="{{ route('dashboard.accounts.festivals.communication', [$account, $edition, 'tab' => $communicationTab]) }}" class="whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-semibold {{ $tab === $communicationTab ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-950' }}" @if($tab === $communicationTab) aria-current="page" @endif>{{ __('app.festival_communication_tab_'.$communicationTab) }}</a>
        @endforeach
    </nav>

    @if ($tab === 'history')
        <section aria-labelledby="festival-queue-stats-title">
            <h2 id="festival-queue-stats-title" class="text-xl font-semibold text-slate-950">{{ __('app.festival_notification_queue_stats') }}</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach (\App\Enums\FestivalNotificationStatus::cases() as $status)
                    @php($statusClass = match($status) { \App\Enums\FestivalNotificationStatus::Pending => 'border-amber-200 bg-amber-50 text-amber-900', \App\Enums\FestivalNotificationStatus::WaitingForSmsCredit => 'border-orange-200 bg-orange-50 text-orange-900', \App\Enums\FestivalNotificationStatus::Sending => 'border-sky-200 bg-sky-50 text-sky-900', \App\Enums\FestivalNotificationStatus::Sent => 'border-emerald-200 bg-emerald-50 text-emerald-900', \App\Enums\FestivalNotificationStatus::Failed => 'border-rose-200 bg-rose-50 text-rose-900', \App\Enums\FestivalNotificationStatus::Cancelled => 'border-stone-200 bg-stone-100 text-stone-800' })
                    <div class="rounded-2xl border p-4 {{ $statusClass }}"><span class="text-sm font-medium">{{ __('app.festival_notification_status_'.$status->value) }}</span><strong class="mt-1 block text-2xl">{{ $notificationStatistics[$status->value] ?? 0 }}</strong></div>
                @endforeach
            </div>
        </section>

        <x-ui.filter-bar :action="route('dashboard.accounts.festivals.communication', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.communication', [$account, $edition, 'tab' => 'history'])" class="lg:grid-cols-4">
            <input type="hidden" name="tab" value="history">
            <label><span class="crm-label">{{ __('app.search') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_notification_search_placeholder') }}"></label>
            <label><span class="crm-label">{{ __('app.type') }}</span><select name="type" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach($notificationTypes as $type)<option value="{{ $type->value }}" @selected($filters['type'] === $type->value)>{{ __('app.festival_notification_type_'.$type->value) }}</option>@endforeach</select></label>
            <label><span class="crm-label">{{ __('app.channel') }}</span><select name="channel" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalNotificationChannel::cases() as $channel)<option value="{{ $channel->value }}" @selected($filters['channel'] === $channel->value)>{{ __('app.festival_notification_channel_'.$channel->value) }}</option>@endforeach</select></label>
            <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach(\App\Enums\FestivalNotificationStatus::cases() as $status)<option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ __('app.festival_notification_status_'.$status->value) }}</option>@endforeach</select></label>
        </x-ui.filter-bar>

        <div class="space-y-4">
            @forelse ($notifications as $notification)
                @php($badgeClass = match($notification->status) { \App\Enums\FestivalNotificationStatus::Pending => 'bg-amber-100 text-amber-800', \App\Enums\FestivalNotificationStatus::WaitingForSmsCredit => 'bg-orange-100 text-orange-800', \App\Enums\FestivalNotificationStatus::Sending => 'bg-sky-100 text-sky-800', \App\Enums\FestivalNotificationStatus::Sent => 'bg-emerald-100 text-emerald-800', \App\Enums\FestivalNotificationStatus::Failed => 'bg-rose-100 text-rose-800', \App\Enums\FestivalNotificationStatus::Cancelled => 'bg-stone-100 text-stone-700' })
                <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong class="text-slate-950">{{ $notification->recipient_name ?: __('app.unknown') }}</strong><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_notification_channel_'.$notification->channel->value) }}</span><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass }}">{{ __('app.festival_notification_status_'.$notification->status->value) }}</span></div><p class="mt-1 break-words text-sm text-slate-600">{{ $notification->channel === \App\Enums\FestivalNotificationChannel::Email ? $notification->recipient_email : ($notification->recipient_phone ?: __('app.not_set')) }}</p></div>
                        <time class="shrink-0 text-xs text-slate-500" datetime="{{ $notification->created_at->toAtomString() }}">{{ $notification->created_at->timezone($edition->timezone)->format('d.m.Y H:i') }}</time>
                    </div>
                    <div class="mt-4"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_notification_type_'.$notification->type->value) }}</p><h3 class="mt-1 font-semibold text-slate-950">{{ $notification->subject ?: __('app.festival_notification_subject') }}</h3><p class="mt-2 line-clamp-3 whitespace-pre-line break-words text-sm text-slate-600">{{ $notification->text }}</p></div>
                    @if ($notification->text)
                        <details class="mt-3 rounded-xl bg-slate-50 p-3"><summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __('app.festival_show_full_text') }}</summary><p class="mt-3 whitespace-pre-line break-words text-sm text-slate-700">{{ $notification->text }}</p></details>
                    @endif
                    @if ($notification->failure_reason)<p class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700"><strong>{{ __('app.festival_failure_reason') }}:</strong> {{ $notification->failure_reason }}</p>@endif
                </article>
            @empty
                <x-ui.empty-state icon="bell">{{ __('app.festival_notifications_empty') }}</x-ui.empty-state>
            @endforelse
        </div>
        <div>{{ $notifications->links() }}</div>
    @elseif ($tab === 'announcements')
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_messages') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_announcements_copy') }}</p></div><x-ui.button type="button" data-festival-announcement-open><x-ui.icon name="plus" class="h-4 w-4" /> {{ __('app.festival_create_announcement') }}</x-ui.button></div>
        <div class="space-y-4">
            @forelse ($announcements as $announcement)
                <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm"><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0"><h3 class="text-lg font-semibold text-slate-950">{{ $announcement->subject }}</h3><p class="mt-2 whitespace-pre-line break-words text-sm text-slate-600">{{ $announcement->body }}</p></div><span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_announcement_status_'.$announcement->status) }}</span></div><p class="mt-4 text-xs text-slate-500">{{ $announcement->scheduled_at?->timezone($edition->timezone)->format('d.m.Y H:i') }}</p></article>
            @empty
                <x-ui.empty-state icon="megaphone">{{ __('app.festival_announcements_empty') }}</x-ui.empty-state>
            @endforelse
        </div>
        <div>{{ $announcements->links() }}</div>

        <div class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true" aria-labelledby="festival-announcement-modal-title" data-festival-announcement-modal data-open="{{ $errors->hasAny(['subject', 'body', 'scheduled_at']) || old('subject') !== null || old('body') !== null || old('scheduled_at') !== null ? 'true' : 'false' }}">
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" data-festival-announcement-panel>
                <div class="flex items-start justify-between gap-4"><div><h2 id="festival-announcement-modal-title" class="text-xl font-semibold text-slate-950">{{ __('app.festival_create_announcement') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_announcement_audience_copy') }}</p></div><button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900" aria-label="{{ __('app.close') }}" data-festival-announcement-close><x-ui.icon name="x" class="h-5 w-5" /></button></div>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.announcements.store', [$account, $edition]) }}" class="mt-6 space-y-5">
                    @csrf
                    <label class="block"><span class="crm-label">{{ __('app.subject') }}</span><input name="subject" value="{{ old('subject') }}" maxlength="255" required class="crm-field" autofocus>@error('subject')<span class="crm-help">{{ $message }}</span>@enderror</label>
                    <label class="block"><span class="crm-label">{{ __('app.message') }}</span><textarea name="body" required rows="8" maxlength="50000" class="crm-field">{{ old('body') }}</textarea>@error('body')<span class="crm-help">{{ $message }}</span>@enderror</label>
                    <label class="block"><span class="crm-label">{{ __('app.festival_send_at') }}</span><input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}" class="crm-field"><span class="crm-help">{{ __('app.festival_send_at_help', ['timezone' => $edition->timezone]) }}</span>@error('scheduled_at')<span class="crm-help">{{ $message }}</span>@enderror</label>
                    <div class="flex flex-wrap justify-end gap-2"><x-ui.button type="button" variant="secondary" data-festival-announcement-close>{{ __('app.cancel') }}</x-ui.button><x-ui.button type="submit"><x-ui.icon name="send" class="h-4 w-4" /> {{ __('app.send') }}</x-ui.button></div>
                </form>
            </div>
        </div>
    @else
        <div><h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_notification_scenarios') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_notification_scenarios_copy') }}</p></div>
        <form method="POST" action="{{ route('dashboard.accounts.festivals.notification-settings.update', $account) }}" class="space-y-4">
            @csrf @method('PUT')
            @foreach ($notificationTypes as $type)
                @php($setting = $notificationSettings->get($type->value))
                <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm"><div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"><div><h3 class="font-semibold text-slate-950">{{ __('app.festival_notification_type_'.$type->value) }}</h3><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_notification_template_ready_copy') }}</p></div><div class="flex flex-wrap gap-3"><label class="flex min-w-36 items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900"><input type="checkbox" checked disabled class="crm-checkbox">{{ __('app.email') }}</label><label class="flex min-w-36 items-center gap-3 rounded-xl border border-stone-200 px-4 py-3 text-sm font-semibold text-slate-800"><input type="checkbox" name="sms[{{ $type->value }}]" value="1" class="crm-checkbox" @checked($setting?->send_sms)>{{ __('app.sms') }}</label></div></div></section>
            @endforeach
            <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" /> {{ __('app.save') }}</x-ui.button>
        </form>
    @endif
</x-festivals.staff.workspace>
@endsection
