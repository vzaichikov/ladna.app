@extends('layouts.app')

@section('title', __('app.notification_settings').' - '.$account->name)

@section('content')
    <div>
        <h1 class="crm-page-title">{{ __('app.notification_settings') }}</h1>
        <p class="crm-page-copy">{{ __('app.notification_settings_copy') }}</p>
    </div>

    <nav class="mt-6 grid grid-cols-3 border-b border-slate-200 sm:flex sm:flex-wrap sm:gap-2" aria-label="{{ __('app.notification_settings') }}">
        @foreach ([
            'customers' => __('app.notifications_customers'),
            'trainers' => __('app.notifications_trainers'),
            'telegram' => __('app.notifications_telegram_bot'),
        ] as $tabKey => $tabLabel)
            <a
                href="{{ route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => $tabKey]) }}"
                class="inline-flex min-w-0 items-center justify-center gap-2 border-b-2 px-2 py-3 text-center text-sm font-semibold transition sm:px-4 {{ $activeTab === $tabKey ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
            >
                {{ $tabLabel }}
            </a>
        @endforeach
    </nav>

    @if ($activeTab === 'trainers')
        @include('accounts.trainer-notification-settings')
    @elseif ($activeTab === 'telegram')
        <div class="mt-6 max-w-4xl rounded-xl border border-violet-200 bg-violet-50 px-5 py-4 text-sm leading-6 text-violet-950">
            {{ __('app.customer_telegram_bot_notification_legend') }}
        </div>

        @include('accounts.customer-telegram-bot-settings')
    @else
        <div class="mt-6 max-w-4xl rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-950">
            {{ __('app.customer_notifications_sms_only_legend') }}
        </div>

        @include('accounts.customer-notification-settings')
    @endif
@endsection
