@extends('layouts.app')

@section('title', __('app.festival_tab_communication').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_communication_title')" :copy="__('app.festival_communication_copy')" />

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <div class="flex items-center justify-between gap-4"><h2 class="text-xl font-semibold">{{ __('app.festival_messages') }}</h2><span class="text-sm text-slate-500">{{ $announcements->total() }}</span></div>
            <div class="mt-4 space-y-3">
                @forelse ($announcements as $announcement)
                    <article class="rounded-xl bg-slate-50 p-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div><strong>{{ $announcement->subject }}</strong><p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $announcement->body }}</p></div>
                            <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_announcement_status_'.$announcement->status) }}</span>
                        </div>
                        <p class="mt-3 text-xs text-slate-500">{{ $announcement->scheduled_at?->timezone($edition->timezone)->format('d.m.Y H:i') }}</p>
                    </article>
                @empty
                    <x-ui.empty-state icon="megaphone">{{ __('app.festival_announcements_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
            <div class="mt-5">{{ $announcements->links() }}</div>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <h2 class="text-lg font-semibold">{{ __('app.festival_new_announcement') }}</h2>
            <form method="POST" action="{{ route('dashboard.accounts.festivals.announcements.store', [$account, $edition]) }}" class="mt-4 space-y-3">
                @csrf
                <label><span class="crm-label">{{ __('app.subject') }}</span><input name="subject" required class="crm-field"></label>
                <label><span class="crm-label">{{ __('app.message') }}</span><textarea name="body" required rows="5" class="crm-field"></textarea></label>
                <label><span class="crm-label">{{ __('app.festival_send_at') }}</span><input type="datetime-local" name="scheduled_at" class="crm-field"></label>
                <x-ui.button type="submit" class="w-full">{{ __('app.send') }}</x-ui.button>
            </form>
        </section>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <h2 class="text-xl font-semibold">{{ __('app.festival_notification_scenarios') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_notification_scenarios_copy') }}</p>
            <form method="POST" action="{{ route('dashboard.accounts.festivals.notification-settings.update', $account) }}" class="mt-4 space-y-2">
                @csrf
                @method('PUT')
                @foreach ($notificationTypes as $type)
                    @php($setting = $notificationSettings->get($type->value))
                    <label class="flex items-start gap-3 rounded-xl border border-stone-200 p-3">
                        <input type="checkbox" name="types[{{ $type->value }}]" value="1" class="crm-checkbox mt-0.5" @checked(! $type->isOptional() || $setting?->is_enabled) @disabled(! $type->isOptional())>
                        <span><strong class="block text-sm">{{ __('app.festival_notification_type_'.$type->value) }}</strong><span class="text-xs text-slate-500">{{ $type->isOptional() ? __('app.optional') : __('app.festival_transactional_required') }}</span></span>
                    </label>
                @endforeach
                <x-ui.button type="submit" class="mt-4">{{ __('app.save') }}</x-ui.button>
            </form>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <h2 class="text-xl font-semibold">{{ __('app.festival_notification_outbox') }}</h2>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach (\App\Enums\FestivalNotificationStatus::cases() as $status)
                    <div class="rounded-xl bg-slate-50 p-3"><span class="text-xs text-slate-500">{{ __('app.festival_notification_status_'.$status->value) }}</span><strong class="mt-1 block text-xl">{{ $notificationStatistics[$status->value] ?? 0 }}</strong></div>
                @endforeach
            </div>
            <div class="mt-5 divide-y divide-stone-100">
                @forelse ($notifications as $notification)
                    <div class="py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2"><strong class="text-sm">{{ __('app.festival_notification_type_'.$notification->type->value) }}</strong><span class="text-xs font-semibold text-slate-500">{{ __('app.festival_notification_status_'.$notification->status->value) }}</span></div>
                        <p class="mt-1 text-xs text-slate-500">{{ $notification->recipient_email }} · {{ $notification->created_at->timezone($edition->timezone)->format('d.m.Y H:i') }}</p>
                        @if ($notification->failure_reason)<p class="mt-1 text-xs text-rose-700">{{ $notification->failure_reason }}</p>@endif
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">{{ __('app.festival_notifications_empty') }}</p>
                @endforelse
            </div>
            <div class="mt-5">{{ $notifications->links() }}</div>
        </section>
    </div>
</x-festivals.staff.workspace>
@endsection
