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
                        @php
                            $fallbackType = $type->settingsFallback();
                            $setting = $notificationSettings->get($type->value)
                                ?? ($fallbackType ? $notificationSettings->get($fallbackType->value) : null);
                        @endphp
                        <div class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs" data-festival-notification-scenario="{{ $type->value }}">
                            <div class="grid gap-4 xl:grid-cols-[minmax(12rem,1fr)_minmax(0,40rem)] xl:items-center">
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-slate-950">{{ __('app.festival_notification_type_'.$type->value) }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_notification_type_'.$type->value.'_copy') }}</p>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-festival-notification-channels>
                                    <label class="flex min-h-12 min-w-0 items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-3 text-sm font-semibold leading-tight text-emerald-900"><input type="checkbox" name="email[{{ $type->value }}]" value="1" class="crm-checkbox shrink-0" @checked($setting?->send_email ?? true)><span>{{ __('app.email') }}</span></label>
                                    <label class="flex min-h-12 min-w-0 items-center gap-3 rounded-xl border border-stone-200 px-3 py-3 text-sm font-semibold leading-tight text-slate-800"><input type="checkbox" name="sms[{{ $type->value }}]" value="1" class="crm-checkbox shrink-0" @checked($setting?->send_sms)><span>{{ __('app.sms') }}</span></label>
                                    <label class="flex min-h-12 min-w-0 items-center gap-3 rounded-xl border border-violet-200 bg-violet-50 px-3 py-3 text-sm font-semibold leading-tight text-violet-900"><input type="checkbox" name="telegram[{{ $type->value }}]" value="1" class="crm-checkbox shrink-0" @checked($setting?->send_telegram ?? true)><span>{{ __('app.festival_participant_telegram_channel') }}</span></label>
                                    <label class="flex min-h-12 min-w-0 items-center gap-3 rounded-xl border border-sky-200 bg-sky-50 px-3 py-3 text-sm font-semibold leading-tight text-sky-900"><input type="checkbox" name="owner_telegram[{{ $type->value }}]" value="1" class="crm-checkbox shrink-0" @checked($setting?->notify_owner_telegram)><span>{{ __('app.festival_owner_telegram_channel') }}</span></label>
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
