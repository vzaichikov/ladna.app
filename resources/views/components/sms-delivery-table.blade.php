@props([
    'deliveries',
    'showAccount' => false,
    'timezone' => null,
])

@php
    $formatMoney = fn (?int $cents): string => \App\Support\MoneyFormatter::format($cents, 'UAH');
    $formatDate = function ($delivery, $date) use ($timezone): string {
        $accountTimezone = $delivery->relationLoaded('account') ? $delivery->account?->timezone : null;
        $deliveryTimezone = $accountTimezone ?: $timezone ?: config('app.timezone');

        return $date?->timezone($deliveryTimezone)->format('d.m.Y H:i') ?? __('app.not_set');
    };
@endphp

<x-ui.panel padding="none" class="mt-6 overflow-hidden">
    @if ($deliveries->isEmpty())
        <x-ui.empty-state :title="__('app.sms_no_deliveries')" icon="bell" class="m-5" />
    @else
        <div class="overflow-x-auto">
            <table @class(['w-full min-w-[1180px] text-left text-sm', 'xl:min-w-[1320px]' => $showAccount])>
                <thead class="bg-stone-50 text-xs font-semibold uppercase text-slate-500">
                    <tr>
                        @if ($showAccount)
                            <th scope="col" class="px-5 py-3">{{ __('app.studio') }}</th>
                        @endif
                        <th scope="col" class="px-5 py-3">{{ __('app.recipient') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('app.sms_purpose') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('app.message') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('app.status') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('app.sms_charge') }}</th>
                        <th scope="col" class="px-5 py-3">{{ __('app.delivery') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($deliveries as $delivery)
                        @php
                            $isCustomerRelated = in_array($delivery->purpose, [
                                \App\Enums\SmsDeliveryPurpose::CustomerNotification,
                                \App\Enums\SmsDeliveryPurpose::CustomerOtp,
                            ], true);
                            $recipientName = $delivery->notification_recipient_name
                                ?: ($isCustomerRelated ? $delivery->resolved_customer_name : null);
                            $recipientId = $delivery->notification_customer_id
                                ?: ($isCustomerRelated ? $delivery->resolved_customer_id : null);
                            $isOtp = $delivery->purpose->isAuthenticationOtp();
                            $message = $delivery->notification_text ?: $delivery->message_preview;
                        @endphp

                        <tr class="align-top">
                            @if ($showAccount)
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-950">{{ $delivery->account?->name ?? __('app.not_set') }}</div>
                                    <div class="mt-1 text-xs text-slate-500">#{{ $delivery->account_id }}</div>
                                </td>
                            @endif
                            <td class="px-5 py-4" data-sms-recipient>
                                <div class="font-semibold text-slate-950">{{ $recipientName ?: __('app.not_set') }}</div>
                                <div class="mt-1 font-mono text-sm text-slate-700">{{ $delivery->recipient_phone }}</div>
                                @if ($recipientId)
                                    <div class="mt-1 text-xs text-slate-500">{{ __('app.customer') }} #{{ $recipientId }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="font-semibold text-slate-950">{{ __('app.sms_purpose_'.$delivery->purpose->value) }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ __('app.sms_mode_'.$delivery->source_mode->value) }}</div>
                            </td>
                            <td class="px-5 py-4" data-sms-message>
                                @if ($isOtp)
                                    <div class="max-w-xl leading-6 text-slate-500">{{ __('app.sms_otp_message_hidden') }}</div>
                                @elseif ($message)
                                    <div class="max-w-xl whitespace-pre-line break-words leading-6 text-slate-700">{{ $message }}</div>
                                @else
                                    <div class="text-slate-500">{{ __('app.not_set') }}</div>
                                @endif
                                @if ($delivery->last_error)
                                    <div class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-xs leading-5 text-rose-800">{{ $delivery->last_error }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="{{ $delivery->status === \App\Enums\SmsDeliveryStatus::Delivered ? 'crm-status-active' : 'crm-status-muted' }}">{{ __('app.sms_delivery_status_'.$delivery->status->value) }}</span>
                                <div class="mt-2 text-xs text-slate-500">
                                    {{ $delivery->billed_segments ?? $delivery->estimated_segments }} {{ __('app.sms_segments_short') }}
                                </div>
                            </td>
                            <td class="px-5 py-4 text-slate-700">
                                <div>{{ __('app.price') }}: {{ $delivery->sms_segment_price_cents === null ? '—' : $formatMoney($delivery->sms_segment_price_cents) }}</div>
                                <div class="mt-1 font-semibold">{{ __('app.amount_uah') }}: {{ $delivery->amount_cents === null ? '—' : $formatMoney($delivery->amount_cents) }}</div>
                            </td>
                            <td class="px-5 py-4 text-slate-700">
                                <div>{{ __('app.provider') }}: {{ $delivery->provider ?: __('app.not_set') }}</div>
                                @if ($delivery->provider_message_id)
                                    <div class="mt-1 font-mono text-xs text-slate-500">{{ $delivery->provider_message_id }}</div>
                                @endif
                                <div class="mt-2 text-xs text-slate-500">{{ $formatDate($delivery, $delivery->created_at) }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.panel>
