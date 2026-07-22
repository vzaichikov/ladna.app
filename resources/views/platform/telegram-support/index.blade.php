@extends('layouts.app')

@section('title', __('app.telegram_support').' - '.__('app.platform'))

@section('content')
    @php
        $formatDate = fn ($date): string => $date
            ? $date->timezone(config('app.timezone'))->format('d.m.Y H:i')
            : __('app.not_set');
        $phoneLabel = fn (?string $phone): string => filled($phone) ? $phone : __('app.not_set');
        $authorizationStatusClass = fn ($status): string => match ($status?->value ?? $status) {
            \App\Enums\TelegramChatAuthorizationStatus::Authorized->value => 'crm-status-active',
            \App\Enums\TelegramChatAuthorizationStatus::Revoked->value => 'crm-status-danger',
            default => 'crm-status-muted',
        };
        $updateStatusClass = fn ($status): string => match ($status?->value ?? $status) {
            \App\Enums\TelegramUpdateStatus::Failed->value => 'crm-status-danger',
            \App\Enums\TelegramUpdateStatus::Processing->value, \App\Enums\TelegramUpdateStatus::Pending->value => 'crm-status-scheduled',
            default => 'crm-status-muted',
        };
        $alertStatusClass = fn ($status): string => match ($status?->value ?? $status) {
            \App\Enums\TelegramAlertStatus::Sent->value => 'crm-status-active',
            \App\Enums\TelegramAlertStatus::Failed->value => 'crm-status-danger',
            \App\Enums\TelegramAlertStatus::Pending->value, \App\Enums\TelegramAlertStatus::Processing->value => 'crm-status-scheduled',
            default => 'crm-status-muted',
        };
        $hasFilters = $search !== ''
            || ($activeTab === 'alerts' && ($alertStatus !== '' || $alertType !== ''));
        $tabUrl = fn (string $tab): string => route('platform.telegram-support.index', array_filter([
            'tab' => $tab,
            'search' => $search,
            'alert_status' => $tab === 'alerts' ? $alertStatus : null,
            'alert_type' => $tab === 'alerts' ? $alertType : null,
        ], fn ($value): bool => filled($value)));
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.telegram_support') }}</h1>
            <p class="crm-page-copy">{{ __('app.telegram_support_copy') }}</p>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-stone-200 bg-white p-2 shadow-crm">
        <div class="grid gap-1 rounded-lg bg-stone-100 p-1 sm:inline-grid sm:grid-flow-col" role="tablist" aria-label="{{ __('app.telegram_support') }}">
            @foreach ($tabs as $tabKey => $tab)
                <a
                    href="{{ $tabUrl($tabKey) }}"
                    id="telegram-support-tab-{{ $tabKey }}"
                    class="crm-tab justify-start sm:justify-center"
                    role="tab"
                    aria-controls="{{ $tab['panel_id'] }}"
                    aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                    tabindex="{{ $activeTab === $tabKey ? '0' : '-1' }}"
                >
                    {{ __($tab['label_key']) }}
                </a>
            @endforeach
        </div>
    </div>

    <form method="GET" action="{{ route('platform.telegram-support.index') }}" class="mt-4 grid gap-4 rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:grid-cols-[1fr_auto] sm:items-end">
        <input type="hidden" name="tab" value="{{ $activeTab }}">

        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="search" value="{{ $search }}" class="crm-field" placeholder="{{ __('app.telegram_support_search_placeholder') }}">
        </label>

        @if ($activeTab === 'alerts')
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="alert_status" class="crm-field">
                    <option value="">{{ __('app.all_statuses') }}</option>
                    @foreach ($alertStatuses as $status)
                        <option value="{{ $status->value }}" @selected($alertStatus === $status->value)>
                            {{ __('app.telegram_alert_status_'.$status->value) }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.telegram_alert_type') }}</span>
                <select name="alert_type" class="crm-field">
                    <option value="">{{ __('app.all_alert_types') }}</option>
                    @foreach ($alertTypes as $type)
                        <option value="{{ $type->value }}" @selected($alertType === $type->value)>
                            {{ __('app.telegram_alert_type_'.$type->value) }}
                        </option>
                    @endforeach
                </select>
            </label>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" variant="secondary">
                <x-ui.icon name="search" class="h-4 w-4" />
                {{ __('app.apply_filters') }}
            </x-ui.button>

            @if ($hasFilters)
                <x-ui.button :href="route('platform.telegram-support.index', ['tab' => $activeTab])" variant="ghost">
                    {{ __('app.reset_filters') }}
                </x-ui.button>
            @endif
        </div>
    </form>

    @if ($activeTab === 'users')
    <x-ui.panel padding="none" id="telegram-support-users" class="mt-6 overflow-hidden" role="tabpanel" aria-labelledby="telegram-support-tab-users">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_linked_users') }}</h2>
        </div>

        @if ($authorizations->isEmpty())
            <x-ui.empty-state :title="__('app.telegram_no_linked_users')" icon="telegram" class="m-5" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1080px] text-left text-sm">
                    <thead class="bg-stone-50 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('app.user') }}</th>
                            <th class="px-5 py-3">{{ __('app.account') }}</th>
                            <th class="px-5 py-3">{{ __('app.telegram_chat') }}</th>
                            <th class="px-5 py-3">{{ __('app.status') }}</th>
                            <th class="px-5 py-3">{{ __('app.updated_at') }}</th>
                            <th class="px-5 py-3 text-right">{{ __('app.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($authorizations as $authorization)
                            @php
                                $linkedName = $authorization->trainer?->name
                                    ?? $authorization->user?->name
                                    ?? $authorization->telegram_username
                                    ?? ('#'.$authorization->id);
                            @endphp
                            <tr class="align-top">
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-950">{{ $linkedName }}</div>
                                    <div class="mt-1 text-sm text-slate-500">{{ $authorization->user?->email ?? __('app.not_set') }}</div>
                                    <div class="mt-1 text-xs font-medium text-slate-500">
                                        {{ __('app.phone') }}:
                                        {{ $phoneLabel($authorization->phone ?? $authorization->trainer?->phone ?? $authorization->user?->phone) }}
                                    </div>
                                    @if ($authorization->trainer)
                                        <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.trainer') }} #{{ $authorization->trainer->id }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-950">{{ $authorization->account?->name ?? __('app.not_set') }}</div>
                                    <div class="mt-1 text-sm text-slate-500">{{ $authorization->account?->slug ?? __('app.not_set') }}</div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-mono text-sm text-slate-950">{{ $authorization->telegram_chat_id }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ __('app.telegram_user_id') }}: {{ $authorization->telegram_user_id ?? __('app.not_set') }}</div>
                                    @if ($authorization->telegram_username)
                                        <div class="mt-1 text-xs text-slate-500">@{{ $authorization->telegram_username }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <span class="{{ $authorizationStatusClass($authorization->status) }}">{{ __('app.telegram_authorization_status_'.$authorization->status->value) }}</span>
                                    <div class="mt-2 text-xs text-slate-500">{{ __('app.active_conversations') }}: {{ $authorization->active_conversations_count }}</div>
                                </td>
                                <td class="px-5 py-4 text-slate-700">
                                    {{ $formatDate($authorization->updated_at) }}
                                    @if ($authorization->revoked_at)
                                        <div class="mt-1 text-xs text-rose-700">{{ __('app.revoked_at') }}: {{ $formatDate($authorization->revoked_at) }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        <x-ui.action-button :href="route('platform.telegram-support.index', ['tab' => 'messages', 'search' => $authorization->telegram_chat_id])" icon="search" :label="__('app.telegram_view_user_logs')" />

                                        <form method="POST" action="{{ route('platform.telegram-support.authorizations.reset', $authorization) }}">
                                            @csrf
                                            <x-ui.action-button type="submit" icon="refresh" :label="__('app.telegram_restart_conversation')" :disabled="$authorization->active_conversations_count === 0" />
                                        </form>

                                        <form method="POST" action="{{ route('platform.telegram-support.authorizations.revoke', $authorization) }}" data-confirm-delete data-confirm-title="{{ __('app.telegram_unlink_user') }}" data-confirm-body="{{ __('app.telegram_unlink_user_confirm') }}" data-confirm-accept="{{ __('app.telegram_unlink_user') }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.action-button type="submit" icon="trash" variant="danger" :label="__('app.telegram_unlink_user')" :disabled="$authorization->status !== \App\Enums\TelegramChatAuthorizationStatus::Authorized" />
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($authorizations->hasPages())
            <div class="border-t border-stone-100 px-5 py-4">
                {{ $authorizations->links() }}
            </div>
        @endif
    </x-ui.panel>

    @elseif ($activeTab === 'messages')
    <x-ui.panel padding="none" id="telegram-support-messages" class="mt-6 overflow-hidden" role="tabpanel" aria-labelledby="telegram-support-tab-messages">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_message_logs') }}</h2>
        </div>

        @forelse ($messages as $message)
            @php
                $contactPhone = data_get($message->payload, 'contact.phone_number');
                $messageText = filled($message->text)
                    ? $message->text
                    : (filled($contactPhone) ? __('app.telegram_contact_shared', ['phone' => $contactPhone]) : __('app.telegram_empty_message'));
                $directionClass = $message->direction === 'outbound' ? 'crm-status-scheduled' : 'crm-status-muted';
            @endphp
            <article class="crm-row lg:grid-cols-[160px_minmax(0,1.2fr)_minmax(0,2fr)_160px] lg:items-start">
                <div>
                    <span class="{{ $directionClass }}">{{ __('app.telegram_direction_'.$message->direction) }}</span>
                    <div class="mt-2 text-xs text-slate-500">{{ $message->message_type }}</div>
                </div>
                <div class="min-w-0">
                    <div class="font-semibold text-slate-950">{{ $message->account?->name ?? __('app.not_set') }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ __('app.telegram_chat') }}: {{ $message->telegram_chat_id }}</div>
                    @if ($message->authorization?->trainer)
                        <div class="mt-1 text-xs text-slate-500">{{ __('app.trainer') }}: {{ $message->authorization->trainer->name }}</div>
                    @elseif ($message->authorization?->user)
                        <div class="mt-1 text-xs text-slate-500">{{ __('app.user') }}: {{ $message->authorization->user->name }}</div>
                    @endif
                </div>
                <div class="min-w-0 text-sm leading-6 text-slate-700">{{ \Illuminate\Support\Str::limit($messageText, 280) }}</div>
                <div class="text-sm text-slate-500">{{ $formatDate($message->sent_at ?? $message->created_at) }}</div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.telegram_no_message_logs')" icon="telegram" class="m-5" />
        @endforelse

        @if ($messages->hasPages())
            <div class="border-t border-stone-100 px-5 py-4">
                {{ $messages->links() }}
            </div>
        @endif
    </x-ui.panel>

    @elseif ($activeTab === 'alerts')
    <x-ui.panel padding="none" id="telegram-support-alerts" class="mt-6 overflow-hidden" role="tabpanel" aria-labelledby="telegram-support-tab-alerts">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_alert_logs') }}</h2>
        </div>

        @forelse ($alerts as $alert)
            @php
                $alertType = $alert->type?->value ?? $alert->type;
                $alertStatus = $alert->status?->value ?? $alert->status;
                $classLabel = data_get($alert->payload, 'class_name')
                    ?? $alert->scheduledClass?->title
                    ?? __('app.not_set');
                $classTime = data_get($alert->payload, 'class_time') ?? __('app.not_set');
                $locationLabel = data_get($alert->payload, 'location_name')
                    ?? $alert->scheduledClass?->location?->name
                    ?? __('app.not_set');
                $roomLabel = data_get($alert->payload, 'room_name')
                    ?? $alert->scheduledClass?->room?->name
                    ?? __('app.not_set');
                $isOwnerAnnouncement = $alertType === \App\Enums\TelegramAlertType::OwnerAnnouncement->value;
            @endphp
            <article class="crm-row lg:grid-cols-[150px_minmax(0,1fr)_minmax(0,2fr)_minmax(0,1.4fr)_160px] lg:items-start">
                <div>
                    <span class="{{ $alertStatusClass($alert->status) }}">{{ __('app.telegram_alert_status_'.$alertStatus) }}</span>
                    <div class="mt-2 text-xs font-medium text-slate-500">{{ __('app.telegram_alert_type_'.$alertType) }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ __('app.attempts') }}: {{ $alert->attempts }}</div>
                </div>
                <div class="min-w-0">
                    <div class="font-semibold text-slate-950">{{ $alert->account?->name ?? __('app.not_set') }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $alert->account?->slug ?? __('app.not_set') }}</div>
                    <div class="mt-2 text-xs text-slate-500">{{ $alert->installation?->bot_username ?? __('app.telegram_bot_profile_owner') }}</div>
                </div>
                <div class="min-w-0 text-sm leading-6 text-slate-700">
                    @if ($isOwnerAnnouncement)
                        <div class="font-semibold text-slate-950">{{ __('app.telegram_alert_type_owner_announcement') }}</div>
                        <div class="text-slate-500">{{ data_get($alert->payload, 'locale', 'uk') }} · {{ \Illuminate\Support\Str::limit((string) data_get($alert->payload, 'source_ref'), 12, '') }}</div>
                    @else
                        <div class="font-semibold text-slate-950">{{ $classLabel }}</div>
                        <div>{{ $classTime }}</div>
                        <div class="text-slate-500">{{ $locationLabel }} · {{ $roomLabel }}</div>
                    @endif
                    <div class="mt-1">{{ \Illuminate\Support\Str::limit((string) $alert->text, 220) }}</div>
                </div>
                <div class="min-w-0 text-sm leading-6 text-slate-700">
                    <div class="font-semibold text-slate-950">{{ $isOwnerAnnouncement ? ($alert->authorization?->user?->name ?? __('app.telegram_alert_recipient_kind_studio_owner')) : ($alert->trainer?->name ?? __('app.trainer_not_assigned')) }}</div>
                    <div class="text-slate-500">{{ __('app.telegram_chat') }}: {{ $alert->telegram_chat_id ?? __('app.not_set') }}</div>
                    @if ($alert->last_error)
                        <div class="mt-1 font-medium text-rose-700">{{ \Illuminate\Support\Str::limit($alert->last_error, 180) }}</div>
                    @endif
                </div>
                <div class="text-sm text-slate-500">
                    {{ $formatDate($alert->sent_at ?? $alert->failed_at ?? $alert->created_at) }}
                    @if ($alert->next_attempt_at)
                        <div class="mt-1 text-xs text-slate-500">{{ __('app.next_attempt_at') }}: {{ $formatDate($alert->next_attempt_at) }}</div>
                    @endif
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.telegram_no_alert_logs')" icon="telegram" class="m-5" />
        @endforelse

        @if ($alerts->hasPages())
            <div class="border-t border-stone-100 px-5 py-4">
                {{ $alerts->links() }}
            </div>
        @endif
    </x-ui.panel>

    @else
    <x-ui.panel padding="none" id="telegram-support-webhooks" class="mt-6 overflow-hidden" role="tabpanel" aria-labelledby="telegram-support-tab-webhooks">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_update_logs') }}</h2>
        </div>

        @forelse ($updates as $update)
            @php
                $payloadText = data_get($update->payload, 'message.text')
                    ?? data_get($update->payload, 'callback_query.data')
                    ?? data_get($update->payload, 'message.contact.phone_number')
                    ?? __('app.not_set');
            @endphp
            <article class="crm-row lg:grid-cols-[150px_minmax(0,1fr)_minmax(0,2fr)_160px] lg:items-start">
                <div>
                    <span class="{{ $updateStatusClass($update->status) }}">{{ __('app.telegram_update_status_'.$update->status->value) }}</span>
                    <div class="mt-2 font-mono text-xs text-slate-500">#{{ $update->update_id }}</div>
                </div>
                <div class="min-w-0">
                    <div class="font-semibold text-slate-950">{{ $update->account?->name ?? __('app.not_set') }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $update->installation?->bot_username ?? __('app.telegram_bot_profile_owner') }}</div>
                </div>
                <div class="min-w-0 text-sm leading-6 text-slate-700">
                    <div>{{ \Illuminate\Support\Str::limit((string) $payloadText, 220) }}</div>
                    @if ($update->error_message)
                        <div class="mt-1 font-medium text-rose-700">{{ \Illuminate\Support\Str::limit($update->error_message, 220) }}</div>
                    @endif
                </div>
                <div class="text-sm text-slate-500">{{ $formatDate($update->received_at ?? $update->created_at) }}</div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.telegram_no_update_logs')" icon="telegram" class="m-5" />
        @endforelse

        @if ($updates->hasPages())
            <div class="border-t border-stone-100 px-5 py-4">
                {{ $updates->links() }}
            </div>
        @endif
    </x-ui.panel>
    @endif
@endsection
