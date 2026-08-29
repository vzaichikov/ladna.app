@extends('layouts.app')

@section('title', __('app.festival_communication_tab_settings').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_communication_tab_settings')" :copy="__('app.festival_notification_scenarios_copy')">
        <p class="mt-2 text-sm font-medium {{ $connectedFestivalOwnerCount > 0 ? 'text-emerald-700' : 'text-amber-700' }}">{{ trans_choice('app.festival_owner_telegram_connections', $connectedFestivalOwnerCount, ['count' => $connectedFestivalOwnerCount]) }}</p>
    </x-ui.page-header>

    @include('festivals.staff._communication-navigation')

    <form method="POST" action="{{ route('dashboard.accounts.festivals.notification-settings.update', $account) }}" class="space-y-6">
        @csrf @method('PUT')
        @foreach (collect($notificationTypes)->groupBy(fn (\App\Enums\FestivalNotificationType $type): string => $type->settingsGroup()) as $group => $types)
            <section class="rounded-2xl border border-stone-200 bg-slate-50/70 p-4 sm:p-5" aria-labelledby="festival-notification-group-{{ $group }}">
                <div class="mb-4">
                    <h2 id="festival-notification-group-{{ $group }}" class="text-lg font-semibold text-slate-950">{{ __('app.festival_notification_group_'.$group) }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_notification_group_'.$group.'_copy') }}</p>
                </div>
                <div class="space-y-3">
                    @foreach ($types as $type)
                        @php($setting = $notificationSettings->get($type->value))
                        <div class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                                <div>
                                    <h3 class="font-semibold text-slate-950">{{ __('app.festival_notification_type_'.$type->value) }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_notification_type_'.$type->value.'_copy') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-3">
                                    <label class="flex min-w-36 items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900"><input type="checkbox" name="email[{{ $type->value }}]" value="1" class="crm-checkbox" @checked($setting?->send_email ?? true)>{{ __('app.email') }}</label>
                                    <label class="flex min-w-36 items-center gap-3 rounded-xl border border-stone-200 px-4 py-3 text-sm font-semibold text-slate-800"><input type="checkbox" name="sms[{{ $type->value }}]" value="1" class="crm-checkbox" @checked($setting?->send_sms)>{{ __('app.sms') }}</label>
                                    <label class="flex min-w-36 items-center gap-3 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm font-semibold text-violet-900"><input type="checkbox" name="telegram[{{ $type->value }}]" value="1" class="crm-checkbox" @checked($setting?->send_telegram ?? true)>{{ __('app.festival_participant_telegram_channel') }}</label>
                                    <label class="flex min-w-48 items-center gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-900"><input type="checkbox" name="owner_telegram[{{ $type->value }}]" value="1" class="crm-checkbox" @checked($setting?->notify_owner_telegram)>{{ __('app.festival_owner_telegram_channel') }}</label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
        <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" /> {{ __('app.save') }}</x-ui.button>
    </form>
</x-festivals.staff.workspace>
@endsection
