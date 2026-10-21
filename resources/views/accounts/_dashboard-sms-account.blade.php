<x-ui.panel class="mt-6" data-sms-account-summary>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.sms_account') }}</h2>
                @if ($hasMultipleWorkingLocations)
                    <x-ui.entity-scope-badge :label="__('app.account_wide')" />
                @endif
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ __('app.sms_sending_mode_'.$smsAccount['mode']->value) }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.sms-account.show', $account)" variant="secondary">
            {{ __('app.open_sms_account') }}
            <x-ui.icon name="chevron-right" class="h-4 w-4" />
        </x-ui.button>
    </div>

    @if ($smsAccount['warningKey'])
        <div role="alert" class="mt-4 rounded-lg border px-4 py-3 text-sm {{ $smsAccount['issue'] === 'outstanding' ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-950' }}">
            {{ __($smsAccount['warningKey'], $smsAccount['warningParameters']) }}
        </div>
    @elseif ($smsAccount['mode'] === \App\Enums\SmsSendingMode::LadnaService && ! $smsAccount['serviceEnabled'])
        <p class="mt-4 text-sm text-amber-900">{{ __('app.sms_service_temporarily_unavailable') }}</p>
    @elseif ($smsAccount['mode'] === \App\Enums\SmsSendingMode::LadnaService && $smsAccount['segmentPriceCents'] === null)
        <p class="mt-4 text-sm text-slate-500">{{ __('app.sms_service_unavailable_for_tariff') }}</p>
    @elseif ($smsAccount['mode'] === \App\Enums\SmsSendingMode::LadnaService && $smsAccount['segmentPriceCents'] === 0)
        <p class="mt-4 text-sm text-emerald-700">{{ __('app.sms_free_tariff_no_top_up') }}</p>
    @elseif ($smsAccount['mode'] !== \App\Enums\SmsSendingMode::LadnaService)
        <p class="mt-4 text-sm text-slate-500">{{ $smsAccount['mode'] === \App\Enums\SmsSendingMode::OwnGateway ? __('app.sms_credit_preserved_own_gateway') : __('app.sms_credit_preserved_disabled') }}</p>
    @endif

    <dl class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            'sms_spendable_credit' => \App\Support\MoneyFormatter::format($smsAccount['spendableCents'], 'UAH'),
            'sms_current_credit' => \App\Support\MoneyFormatter::format($smsAccount['balanceCents'], 'UAH'),
            'sms_pending_reservations' => \App\Support\MoneyFormatter::format($smsAccount['reservedCents'], 'UAH'),
            'sms_approximate_segments' => $smsAccount['approximateSegments'] === null ? ($smsAccount['segmentPriceCents'] === 0 ? '∞' : __('app.not_available')) : number_format($smsAccount['approximateSegments'], 0, '.', ' '),
        ] as $label => $value)
            <div class="rounded-lg bg-slate-50 px-4 py-3">
                <dt class="text-sm text-slate-500">{{ __('app.'.$label) }}</dt>
                <dd class="mt-1 text-xl font-semibold text-slate-950">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</x-ui.panel>
