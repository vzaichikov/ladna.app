@extends('layouts.app')

@section('title', ($series->exists ? __('app.festival_series_edit') : __('app.festival_series_create')).' - '.$account->name)

@section('content')
<div class="mx-auto max-w-4xl space-y-6">
    <x-ui.page-header :title="$series->exists ? __('app.festival_series_edit') : __('app.festival_series_create')" />

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $series->exists ? route('dashboard.accounts.festivals.series.update', [$account, $series]) : route('dashboard.accounts.festivals.series.store', $account) }}" class="space-y-6">
        @csrf
        @if ($series->exists)
            @method('PUT')
        @endif

        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="sm:col-span-2">
                    <span class="crm-label">{{ __('app.name') }}</span>
                    <input name="name" value="{{ old('name', $series->name) }}" required maxlength="255" class="crm-field">
                </label>
                <label class="sm:col-span-2">
                    <span class="crm-label">{{ __('app.summary') }}</span>
                    <textarea name="summary" rows="3" maxlength="500" class="crm-field">{{ old('summary', $series->summary) }}</textarea>
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_organizer') }}</span>
                    <input name="organizer_name" value="{{ old('organizer_name', $series->organizer_name) }}" maxlength="255" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.email') }}</span>
                    <input type="email" name="organizer_email" value="{{ old('organizer_email', $series->organizer_email) }}" maxlength="255" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.phone') }}</span>
                    <input name="organizer_phone" value="{{ old('organizer_phone', $series->organizer_phone) }}" maxlength="50" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.brand_color') }}</span>
                    <input name="brand_color" value="{{ old('brand_color', $series->brand_color) }}" placeholder="#10233F" pattern="#[0-9a-fA-F]{6}" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_telegram_url') }}</span>
                    <input type="url" name="organizer_telegram_url" value="{{ old('organizer_telegram_url', $series->organizer_telegram_url) }}" class="crm-field">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_instagram_url') }}</span>
                    <input type="url" name="organizer_instagram_url" value="{{ old('organizer_instagram_url', $series->organizer_instagram_url) }}" class="crm-field">
                </label>
                <label class="flex items-center gap-3 sm:col-span-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" class="crm-checkbox" @checked(old('is_active', $series->exists ? $series->is_active : true))>
                    <span class="text-sm font-semibold text-slate-700">{{ __('app.active') }}</span>
                </label>
            </div>
        </section>

        <div class="flex justify-end">
            <x-ui.button type="submit" size="lg">{{ __('app.save') }}</x-ui.button>
        </div>
    </form>

    @if ($series->exists)
        <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_telegram_bot_title') }}</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">{{ __('app.festival_telegram_bot_help') }}</p>
                </div>
                @if ($telegramInstallation?->is_enabled)
                    <span class="crm-status-active">{{ __('app.telegram_webhook_synced') }}</span>
                @elseif ($telegramInstallation?->tokenValue())
                    <span class="crm-status-warning">{{ __('app.telegram_bot_disabled') }}</span>
                @else
                    <span class="crm-status-muted">{{ __('app.telegram_not_configured') }}</span>
                @endif
            </div>

            @if ($telegramInstallation?->bot_username)
                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-3">
                    <div class="rounded-xl bg-slate-50 p-4">
                        <dt class="text-slate-500">{{ __('app.telegram_bot') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ '@'.$telegramInstallation->bot_username }}</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <dt class="text-slate-500">{{ __('app.telegram_bot_token') }}</dt>
                        <dd class="mt-1 font-mono font-semibold text-slate-950">••••{{ $telegramInstallation->token_last_four }}</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <dt class="text-slate-500">{{ __('app.status') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ __('app.'.$telegramInstallation->status) }}</dd>
                    </div>
                </dl>
            @endif

            @if ($canManageTelegramToken)
                <form method="POST" action="{{ route('dashboard.accounts.festivals.series.telegram-bot.update', [$account, $series]) }}" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
                    @csrf
                    @method('PUT')
                    <label class="min-w-0 flex-1">
                        <span class="crm-label">{{ __('app.telegram_bot_token') }}</span>
                        <input type="password" name="token" autocomplete="off" class="crm-field" placeholder="{{ $telegramInstallation?->tokenValue() ? __('app.telegram_bot_token_keep_placeholder') : '' }}">
                        <x-ui.field-error name="token" />
                    </label>
                    <x-ui.button type="submit">{{ $telegramInstallation?->tokenValue() ? __('app.telegram_bot_reconnect') : __('app.telegram_bot_connect') }}</x-ui.button>
                </form>
            @else
                <p class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">{{ __('app.festival_telegram_token_permission_required') }}</p>
            @endif

            @if ($telegramInstallation?->tokenValue())
                <div class="mt-5 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.series.telegram-bot.check', [$account, $series]) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">{{ __('app.telegram_check_connection') }}</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.series.telegram-bot.reconnect', [$account, $series]) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">{{ __('app.telegram_bot_reconnect') }}</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.series.telegram-bot.disable', [$account, $series]) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary">{{ __('app.disable') }}</x-ui.button>
                    </form>
                    @if ($canManageTelegramToken)
                        <form method="POST" action="{{ route('dashboard.accounts.festivals.series.telegram-bot.destroy', [$account, $series]) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger">{{ __('app.disconnect') }}</x-ui.button>
                        </form>
                    @endif
                </div>
            @endif
        </section>
    @endif
</div>
@endsection
