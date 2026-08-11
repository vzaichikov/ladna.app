@extends('layouts.app')

@section('title', __('app.telegram_customer_connection_manager').' - '.$account->name)

@section('content')
    @php
        $formatDate = fn ($date): string => $date
            ? $date->timezone($account->timezone)->format('d.m.Y H:i')
            : __('app.not_set');
        $authorizationStatusClass = fn ($status): string => match ($status?->value ?? $status) {
            \App\Enums\TelegramChatAuthorizationStatus::Authorized->value => 'crm-status-active',
            \App\Enums\TelegramChatAuthorizationStatus::Revoked->value => 'crm-status-danger',
            default => 'crm-status-muted',
        };
        $updateStatusClass = fn ($status): string => match ($status?->value ?? $status) {
            \App\Enums\TelegramUpdateStatus::Failed->value => 'crm-status-danger',
            \App\Enums\TelegramUpdateStatus::Processing->value, \App\Enums\TelegramUpdateStatus::Pending->value => 'crm-status-scheduled',
            \App\Enums\TelegramUpdateStatus::Processed->value => 'crm-status-active',
            default => 'crm-status-muted',
        };
        $notificationStatusClass = fn ($status): string => match ($status?->value ?? $status) {
            \App\Enums\CustomerNotificationStatus::Sent->value => 'crm-status-active',
            \App\Enums\CustomerNotificationStatus::Failed->value => 'crm-status-danger',
            \App\Enums\CustomerNotificationStatus::Cancelled->value, \App\Enums\CustomerNotificationStatus::Skipped->value => 'crm-status-muted',
            default => 'crm-status-scheduled',
        };
        $tabUrl = fn (string $tab): string => route('dashboard.accounts.telegram-connections.index', array_filter([
            'account' => $account,
            'tab' => $tab,
            'search' => $search,
        ], fn ($value): bool => filled($value)));
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.telegram_customer_connection_manager') }}</h1>
            <p class="crm-page-copy">{{ __('app.telegram_customer_connection_manager_copy', ['studio' => $account->name]) }}</p>
        </div>
    </div>

    @if (! $installation?->token_last_four)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">
            {{ __('app.telegram_connection_manager_bot_missing') }}
        </div>
    @endif

    <dl class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
            <dt class="text-sm text-slate-500">{{ __('app.telegram_active_connections') }}</dt>
            <dd class="mt-2 text-2xl font-semibold text-slate-950">{{ $activeConnectionsCount }}</dd>
            <p class="mt-1 text-xs text-slate-500">{{ __('app.telegram_total_connections', ['count' => $totalConnectionsCount]) }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
            <dt class="text-sm text-slate-500">{{ __('app.telegram_bot_username') }}</dt>
            <dd class="mt-2 break-all font-semibold text-slate-950">{{ $installation?->bot_username ? '@'.ltrim($installation->bot_username, '@') : '—' }}</dd>
            <p class="mt-1 text-xs text-slate-500">{{ $installation?->status ? __('app.'.$installation->status) : __('app.not_connected') }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
            <dt class="text-sm text-slate-500">{{ __('app.telegram_last_incoming_update') }}</dt>
            <dd class="mt-2 font-semibold text-slate-950">{{ $formatDate($latestUpdate?->received_at ?? $latestUpdate?->created_at) }}</dd>
            <p class="mt-1 text-xs text-slate-500">{{ $latestUpdate?->status ? __('app.telegram_update_status_'.$latestUpdate->status->value) : __('app.not_set') }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
            <dt class="text-sm text-slate-500">{{ __('app.telegram_last_message') }}</dt>
            <dd class="mt-2 font-semibold text-slate-950">{{ $formatDate($latestMessage?->sent_at ?? $latestMessage?->created_at) }}</dd>
            <p class="mt-1 text-xs text-slate-500">{{ $latestMessage?->direction ? __('app.telegram_direction_'.$latestMessage->direction) : __('app.not_set') }}</p>
        </div>
    </dl>

    <div class="mt-6 rounded-xl border border-stone-200 bg-white p-2 shadow-crm">
        <div class="grid gap-1 rounded-lg bg-stone-100 p-1 sm:inline-grid sm:grid-flow-col" role="tablist" aria-label="{{ __('app.telegram_customer_connection_manager') }}">
            @foreach ($tabs as $tabKey => $tab)
                <a
                    href="{{ $tabUrl($tabKey) }}"
                    id="telegram-customer-tab-{{ $tabKey }}"
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

    <form method="GET" action="{{ route('dashboard.accounts.telegram-connections.index', $account) }}" class="mt-4 grid gap-4 rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:grid-cols-[1fr_auto] sm:items-end">
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="search" value="{{ $search }}" class="crm-field" placeholder="{{ __('app.telegram_customer_connection_search_placeholder') }}">
        </label>
        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" variant="secondary">
                <x-ui.icon name="search" class="h-4 w-4" />
                {{ __('app.apply_filters') }}
            </x-ui.button>
            @if ($search !== '')
                <x-ui.button :href="route('dashboard.accounts.telegram-connections.index', [$account, 'tab' => $activeTab])" variant="ghost">
                    {{ __('app.reset_filters') }}
                </x-ui.button>
            @endif
        </div>
    </form>

    @if ($activeTab === 'connections')
        <x-ui.panel padding="none" id="telegram-customer-connections" class="mt-6 overflow-hidden" role="tabpanel" aria-labelledby="telegram-customer-tab-connections">
            <div class="border-b border-stone-100 p-5">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_customer_connections') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.telegram_customer_connections_copy') }}</p>
            </div>

            @if ($authorizations->isEmpty())
                <x-ui.empty-state :title="__('app.telegram_no_customer_connections')" icon="telegram" class="m-5" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[960px] text-left text-sm">
                        <thead class="bg-stone-50 text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-3">{{ __('app.customer') }}</th>
                                <th class="px-5 py-3">{{ __('app.telegram_chat') }}</th>
                                <th class="px-5 py-3">{{ __('app.status') }}</th>
                                <th class="px-5 py-3">{{ __('app.updated_at') }}</th>
                                <th class="px-5 py-3 text-right">{{ __('app.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($authorizations as $authorization)
                                <tr class="align-top">
                                    <td class="px-5 py-4">
                                        @if ($authorization->customer)
                                            <a href="{{ route('dashboard.accounts.customers.edit', [$account, $authorization->customer]) }}" class="font-semibold text-brand-700 hover:text-brand-800">
                                                {{ $authorization->customer->name }}
                                            </a>
                                        @else
                                            <div class="font-semibold text-slate-950">{{ __('app.not_set') }}</div>
                                        @endif
                                        <div class="mt-1 text-sm text-slate-500">{{ $authorization->customer?->email ?? __('app.not_set') }}</div>
                                        <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.phone') }}: {{ $authorization->phone ?? $authorization->customer?->phone ?? __('app.not_set') }}</div>
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
                                    </td>
                                    <td class="px-5 py-4 text-slate-700">
                                        {{ $formatDate($authorization->updated_at) }}
                                        @if ($authorization->revoked_at)
                                            <div class="mt-1 text-xs text-rose-700">{{ __('app.revoked_at') }}: {{ $formatDate($authorization->revoked_at) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex justify-end gap-2">
                                            <x-ui.action-button :href="route('dashboard.accounts.telegram-connections.index', [$account, 'tab' => 'messages', 'search' => $authorization->telegram_chat_id])" icon="search" :label="__('app.telegram_view_user_logs')" />

                                            <form method="POST" action="{{ route('dashboard.accounts.telegram-connections.reset', [$account, $authorization]) }}">
                                                @csrf
                                                <x-ui.action-button type="submit" icon="refresh" :label="__('app.telegram_reset_customer_session')" :disabled="$authorization->status !== \App\Enums\TelegramChatAuthorizationStatus::Authorized" />
                                            </form>

                                            <form method="POST" action="{{ route('dashboard.accounts.telegram-connections.revoke', [$account, $authorization]) }}" data-confirm-delete data-confirm-title="{{ __('app.telegram_unlink_customer') }}" data-confirm-body="{{ __('app.telegram_unlink_customer_confirm') }}" data-confirm-accept="{{ __('app.telegram_unlink_customer') }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.action-button type="submit" icon="trash" variant="danger" :label="__('app.telegram_unlink_customer')" :disabled="$authorization->status !== \App\Enums\TelegramChatAuthorizationStatus::Authorized" />
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
                <div class="border-t border-stone-100 px-5 py-4">{{ $authorizations->links() }}</div>
            @endif
        </x-ui.panel>
    @elseif ($activeTab === 'messages')
        <x-ui.panel padding="none" id="telegram-customer-messages" class="mt-6 overflow-hidden" role="tabpanel" aria-labelledby="telegram-customer-tab-messages">
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
                <article class="crm-row lg:grid-cols-[150px_minmax(0,1fr)_minmax(0,2fr)_160px] lg:items-start">
                    <div>
                        <span class="{{ $directionClass }}">{{ __('app.telegram_direction_'.$message->direction) }}</span>
                        <div class="mt-2 text-xs text-slate-500">{{ $message->message_type }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-950">{{ $message->authorization?->customer?->name ?? __('app.not_set') }}</div>
                        <div class="mt-1 text-sm text-slate-500">{{ __('app.telegram_chat') }}: {{ $message->telegram_chat_id }}</div>
                    </div>
                    <div class="min-w-0 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ \Illuminate\Support\Str::limit($messageText, 280) }}</div>
                    <div class="text-sm text-slate-500">{{ $formatDate($message->sent_at ?? $message->created_at) }}</div>
                </article>
            @empty
                <x-ui.empty-state :title="__('app.telegram_no_message_logs')" icon="telegram" class="m-5" />
            @endforelse

            @if ($messages->hasPages())
                <div class="border-t border-stone-100 px-5 py-4">{{ $messages->links() }}</div>
            @endif
        </x-ui.panel>
    @elseif ($activeTab === 'notifications')
        <x-ui.panel padding="none" id="telegram-customer-notifications" class="mt-6 overflow-hidden" role="tabpanel" aria-labelledby="telegram-customer-tab-notifications">
            <div class="border-b border-stone-100 p-5">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_customer_notification_logs') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.telegram_customer_notification_logs_copy') }}</p>
            </div>

            @forelse ($notifications as $notification)
                <article class="crm-row lg:grid-cols-[150px_minmax(0,1.2fr)_minmax(0,2fr)_minmax(0,1fr)_160px] lg:items-start">
                    <div>
                        <span class="{{ $notificationStatusClass($notification->status) }}">{{ __('app.customer_notification_status_'.$notification->status->value) }}</span>
                        <div class="mt-2 text-xs text-slate-500">{{ __($notification->type->labelKey()) }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ __('app.attempts') }}: {{ $notification->attempts }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-950">{{ $notification->recipient_name ?: ($notification->customer?->name ?? __('app.not_set')) }}</div>
                        <div class="mt-1 font-mono text-sm text-slate-500">{{ $notification->recipient_phone ?: ($notification->customer?->phone ?? __('app.not_set')) }}</div>
                    </div>
                    <div class="min-w-0 text-sm leading-6 text-slate-700">
                        <div class="whitespace-pre-wrap break-words">{{ \Illuminate\Support\Str::limit((string) $notification->text, 280) }}</div>
                        @if ($notification->last_error)
                            <div class="mt-1 font-medium text-rose-700">{{ \Illuminate\Support\Str::limit($notification->last_error, 180) }}</div>
                        @endif
                    </div>
                    <div class="text-sm text-slate-700">
                        <div>{{ __('app.telegram_delivery_channel') }}: {{ $notification->resolved_channel ? __('app.customer_notification_channel_'.$notification->resolved_channel->value) : __('app.not_set') }}</div>
                        @if ($notification->fallback_used_at)
                            <div class="mt-1 text-xs font-medium text-amber-700">{{ __('app.telegram_sms_fallback_used') }}</div>
                        @endif
                        @if ($notification->provider_message_id)
                            <div class="mt-1 break-all font-mono text-xs text-slate-500">{{ $notification->provider_message_id }}</div>
                        @endif
                    </div>
                    <div class="text-sm text-slate-500">{{ $formatDate($notification->sent_at ?? $notification->failed_at ?? $notification->created_at) }}</div>
                </article>
            @empty
                <x-ui.empty-state :title="__('app.telegram_no_customer_notification_logs')" icon="bell" class="m-5" />
            @endforelse

            @if ($notifications->hasPages())
                <div class="border-t border-stone-100 px-5 py-4">{{ $notifications->links() }}</div>
            @endif
        </x-ui.panel>
    @else
        <x-ui.panel padding="none" id="telegram-customer-updates" class="mt-6 overflow-hidden" role="tabpanel" aria-labelledby="telegram-customer-tab-updates">
            <div class="border-b border-stone-100 p-5">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_update_logs') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.telegram_customer_update_logs_copy') }}</p>
            </div>

            @forelse ($updates as $update)
                @php
                    $payloadText = data_get($update->payload, 'message.text')
                        ?? data_get($update->payload, 'callback_query.data')
                        ?? data_get($update->payload, 'message.contact.phone_number')
                        ?? __('app.not_set');
                @endphp
                <article class="crm-row lg:grid-cols-[150px_minmax(0,2fr)_minmax(0,1fr)_160px] lg:items-start">
                    <div>
                        <span class="{{ $updateStatusClass($update->status) }}">{{ __('app.telegram_update_status_'.$update->status->value) }}</span>
                        <div class="mt-2 font-mono text-xs text-slate-500">#{{ $update->update_id }}</div>
                    </div>
                    <div class="min-w-0 text-sm leading-6 text-slate-700">
                        <div>{{ \Illuminate\Support\Str::limit((string) $payloadText, 220) }}</div>
                        @if ($update->error_message)
                            <div class="mt-1 font-medium text-rose-700">{{ \Illuminate\Support\Str::limit($update->error_message, 220) }}</div>
                        @endif
                    </div>
                    <div class="text-sm text-slate-500">
                        {{ __('app.attempts') }}: {{ $update->attempts }}
                        @if ($update->processed_at)
                            <div class="mt-1 text-xs">{{ __('app.telegram_processed_at') }}: {{ $formatDate($update->processed_at) }}</div>
                        @endif
                    </div>
                    <div class="text-sm text-slate-500">{{ $formatDate($update->received_at ?? $update->created_at) }}</div>
                </article>
            @empty
                <x-ui.empty-state :title="__('app.telegram_no_customer_update_logs')" icon="telegram" class="m-5" />
            @endforelse

            @if ($updates->hasPages())
                <div class="border-t border-stone-100 px-5 py-4">{{ $updates->links() }}</div>
            @endif
        </x-ui.panel>
    @endif
@endsection
