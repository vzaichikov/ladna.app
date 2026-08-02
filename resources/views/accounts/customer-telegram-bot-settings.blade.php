<div class="mt-6 max-w-6xl">
    @error('telegram_bot')
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm leading-6 text-rose-800">
            {{ $message }}
        </div>
    @enderror

    <div @class([
        'grid gap-6',
        'max-w-4xl' => ! $telegramBotLink,
        'lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start' => $telegramBotLink,
    ]) data-customer-telegram-settings-layout>
        <div class="space-y-6">
            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.customer_telegram_bot_settings') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_telegram_bot_settings_copy') }}</p>
            </div>

            @if ($telegramBotInstallation?->is_enabled)
                <span class="crm-status-active">{{ __('app.active') }}</span>
            @elseif ($telegramBotInstallation?->token_last_four)
                <span class="crm-status-warning">{{ __('app.telegram_bot_needs_attention') }}</span>
            @else
                <span class="crm-status-muted">{{ __('app.not_connected') }}</span>
            @endif
        </div>

        <ol class="mt-5 grid gap-3 text-sm leading-6 text-slate-600 sm:grid-cols-3">
            <li class="rounded-lg bg-slate-50 p-4"><strong class="block text-slate-950">1. {{ __('app.telegram_bot_setup_create_title') }}</strong>{{ __('app.telegram_bot_setup_create_copy') }}</li>
            <li class="rounded-lg bg-slate-50 p-4"><strong class="block text-slate-950">2. {{ __('app.telegram_bot_setup_token_title') }}</strong>{{ __('app.telegram_bot_setup_token_copy') }}</li>
            <li class="rounded-lg bg-slate-50 p-4"><strong class="block text-slate-950">3. {{ __('app.telegram_bot_setup_share_title') }}</strong>{{ __('app.telegram_bot_setup_share_copy') }}</li>
        </ol>

        <form method="POST" action="{{ route('dashboard.accounts.customer-telegram-bot.update', $account) }}" class="mt-6 grid gap-4">
            @csrf
            @method('PUT')

            <label class="block">
                <span class="crm-label">{{ __('app.telegram_bot_token') }}</span>
                <input
                    type="password"
                    name="token"
                    class="crm-field"
                    maxlength="255"
                    autocomplete="off"
                    placeholder="{{ $telegramBotInstallation?->token_last_four ? __('app.keep_existing_token_last_four', ['last_four' => $telegramBotInstallation->token_last_four]) : __('app.telegram_bot_token_placeholder') }}"
                >
                <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.telegram_bot_token_private_copy') }}</span>
                @error('token')<span class="crm-help text-rose-700">{{ $message }}</span>@enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.welcome_message') }}</span>
                <textarea name="welcome_message" rows="3" class="crm-field" maxlength="1000">{{ old('welcome_message', $telegramBotProfile?->welcome_message) }}</textarea>
                <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.telegram_bot_welcome_message_copy') }}</span>
            </label>

            <div>
                <x-ui.button type="submit">
                    <x-ui.icon name="plug" class="h-4 w-4" />
                    {{ $telegramBotInstallation?->token_last_four ? __('app.telegram_bot_save_and_reconnect') : __('app.telegram_bot_connect') }}
                </x-ui.button>
            </div>
        </form>
            </section>

            @if ($telegramBotInstallation?->token_last_four)
                <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_bot_connection') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.telegram_bot_connection_copy') }}</p>
                </div>
                <x-ui.button :href="route('dashboard.accounts.telegram-connections.index', $account)" variant="secondary" class="self-start">
                    <x-ui.icon name="users" class="h-4 w-4" />
                    {{ __('app.telegram_manage_connections') }}
                </x-ui.button>
            </div>

            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div class="rounded-lg bg-slate-50 p-4">
                    <dt class="text-slate-500">{{ __('app.telegram_bot_username') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-950">{{ $telegramBotInstallation->bot_username ? '@'.ltrim($telegramBotInstallation->bot_username, '@') : '—' }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-4">
                    <dt class="text-slate-500">{{ __('app.telegram_webhook_status') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-950">{{ __('app.'.$telegramBotInstallation->status) }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-4">
                    <dt class="text-slate-500">{{ __('app.telegram_bot_token') }}</dt>
                    <dd class="mt-1 font-mono text-slate-950">••••{{ $telegramBotInstallation->token_last_four }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-4">
                    <dt class="text-slate-500">{{ __('app.telegram_last_synced') }}</dt>
                    <dd class="mt-1 text-slate-950">{{ $telegramBotInstallation->last_webhook_synced_at?->timezone($account->timezone)->format('d.m.Y H:i') ?? '—' }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-4">
                    <dt class="text-slate-500">{{ __('app.telegram_active_connections') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-950">{{ $telegramBotActiveConnectionsCount }}</dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-4">
                    <dt class="text-slate-500">{{ __('app.telegram_last_incoming_update') }}</dt>
                    <dd class="mt-1 text-slate-950">
                        {{ $telegramBotLastUpdate?->received_at?->timezone($account->timezone)->format('d.m.Y H:i') ?? '—' }}
                        @if ($telegramBotLastUpdate?->status)
                            <span class="ml-1 text-xs text-slate-500">· {{ __('app.telegram_update_status_'.$telegramBotLastUpdate->status->value) }}</span>
                        @endif
                    </dd>
                </div>
                <div class="rounded-lg bg-slate-50 p-4 sm:col-span-2">
                    <dt class="text-slate-500">{{ __('app.telegram_last_message') }}</dt>
                    <dd class="mt-1 text-slate-950">
                        {{ $telegramBotLastMessage?->sent_at?->timezone($account->timezone)->format('d.m.Y H:i') ?? '—' }}
                        @if ($telegramBotLastMessage?->direction)
                            <span class="ml-1 text-xs text-slate-500">· {{ __('app.telegram_direction_'.$telegramBotLastMessage->direction) }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($latestTelegramBotError)
                <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">{{ $latestTelegramBotError }}</p>
            @endif

            <form
                class="mt-5 rounded-lg border border-stone-200 bg-stone-50 p-4"
                data-telegram-webhook-status-url="{{ route('dashboard.accounts.customer-telegram-bot.webhook-status', $account) }}"
                data-telegram-webhook-register-url="{{ route('dashboard.accounts.customer-telegram-bot.register-webhook', $account) }}"
                data-telegram-webhook-delete-url="{{ route('dashboard.accounts.customer-telegram-bot.delete-webhook', $account) }}"
                data-telegram-webhook-loading="{{ __('app.telegram_webhook_status_loading') }}"
                data-telegram-webhook-unknown="{{ __('app.telegram_webhook_status_unknown') }}"
                data-telegram-webhook-status-failed="{{ __('app.telegram_webhook_status_failed') }}"
                data-telegram-webhook-registered="{{ __('app.telegram_webhook_registered') }}"
                data-telegram-webhook-not-registered="{{ __('app.telegram_webhook_not_registered') }}"
                data-telegram-webhook-url-mismatch="{{ __('app.telegram_webhook_url_mismatch') }}"
            >
                @csrf
                <div data-telegram-webhook-panel>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-950">{{ __('app.telegram_webhook_status') }}</h3>
                            <p class="mt-1 text-sm text-slate-500" data-telegram-webhook-summary>{{ __('app.telegram_webhook_status_unknown') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-brand-200 hover:text-brand-700" data-telegram-webhook-refresh>
                                {{ __('app.refresh') }}
                            </button>
                            <button type="button" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-600/20 transition hover:bg-brand-700" data-telegram-webhook-register>
                                {{ __('app.telegram_register_webhook') }}
                            </button>
                            <button type="button" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100" data-telegram-webhook-delete>
                                {{ __('app.telegram_delete_webhook') }}
                            </button>
                        </div>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_webhook_local_status') }}</dt>
                            <dd class="mt-1 text-slate-700" data-telegram-webhook-local>{{ __('app.'.$telegramBotInstallation->status) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_webhook_live_status') }}</dt>
                            <dd class="mt-1 text-slate-700" data-telegram-webhook-live>{{ __('app.telegram_not_checked') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_last_synced_at') }}</dt>
                            <dd class="mt-1 text-slate-700" data-telegram-webhook-synced>{{ $telegramBotInstallation->last_webhook_synced_at?->timezone($account->timezone)->format('d.m.Y H:i') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_pending_updates') }}</dt>
                            <dd class="mt-1 text-slate-700" data-telegram-webhook-pending>—</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase text-slate-400">{{ __('app.telegram_registered_url') }}</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-slate-700" data-telegram-webhook-url>—</dd>
                        </div>
                        <div class="hidden sm:col-span-2" data-telegram-webhook-error-row>
                            <dt class="text-xs font-semibold uppercase text-rose-400">{{ __('app.telegram_last_error') }}</dt>
                            <dd class="mt-1 text-rose-700" data-telegram-webhook-error></dd>
                        </div>
                    </dl>
                </div>
            </form>

            <div class="mt-5 flex flex-wrap gap-3">
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.customer-telegram-bot.destroy', $account) }}"
                    data-confirm-delete
                    data-confirm-title="{{ __('app.telegram_bot_disconnect') }}"
                    data-confirm-body="{{ __('app.telegram_bot_disconnect_confirm') }}"
                    data-confirm-accept="{{ __('app.telegram_bot_disconnect') }}"
                >
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">{{ __('app.telegram_bot_disconnect') }}</x-ui.button>
                </form>
            </div>
                </section>
            @endif
        </div>

        @if ($telegramBotLink)
            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6" data-customer-telegram-share-card>
                <div class="grid gap-6 sm:grid-cols-[1fr_auto] sm:items-center lg:grid-cols-1">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.telegram_bot_share_with_customers') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.telegram_bot_share_with_customers_copy') }}</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <x-ui.button :href="$telegramBotLink" target="_blank" rel="noopener">{{ __('app.open_bot') }}</x-ui.button>
                        <x-ui.button type="button" variant="secondary" data-copy-button data-copy-value="{{ $telegramBotLink }}" data-copy-success-label="{{ __('app.copied') }}">{{ __('app.copy_link') }}</x-ui.button>
                    </div>
                </div>
                <div class="w-40 rounded-xl border border-stone-200 bg-white p-3 text-slate-950 sm:justify-self-end lg:justify-self-center [&_svg]:h-auto [&_svg]:w-full">
                    {!! $telegramBotQrSvg !!}
                </div>
            </div>
            </section>
        @endif
    </div>
</div>
