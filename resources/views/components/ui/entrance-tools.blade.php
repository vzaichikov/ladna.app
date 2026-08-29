@props([
    'searchUrl',
    'cashSaleUrl',
    'cardSaleUrl',
    'ticketTypes' => collect(),
    'paymentProviders' => collect(),
    'currency' => null,
    'canSell' => true,
    'searchLabel' => null,
    'searchHint' => null,
    'searchPlaceholder' => null,
    'noPeopleLabel' => null,
])

@php
    $ticketTypes = collect($ticketTypes);
    $paymentProviders = collect($paymentProviders);
    $searchLabel ??= __('app.entrance_search_guests');
    $searchHint ??= __('app.entrance_guest_search_hint');
    $searchPlaceholder ??= __('app.entrance_guest_search_placeholder');
    $noPeopleLabel ??= __('app.entrance_no_guests_found');
@endphp

<section
    {{ $attributes->class(['rounded-2xl border border-stone-200 bg-white p-4 shadow-crm sm:p-5']) }}
    data-entrance-tools
    data-search-url="{{ $searchUrl }}"
    data-cash-sale-url="{{ $cashSaleUrl }}"
    data-card-sale-url="{{ $cardSaleUrl }}"
    data-csrf-token="{{ csrf_token() }}"
    data-cash-title="{{ __('app.entrance_cash_ticket') }}"
    data-card-title="{{ __('app.entrance_card_ticket') }}"
    data-cash-submit-label="{{ __('app.entrance_issue_cash_ticket') }}"
    data-card-submit-label="{{ __('app.entrance_create_payment') }}"
    data-cash-success-label="{{ __('app.entrance_cash_ticket_ready') }}"
    data-card-ready-label="{{ __('app.entrance_payment_qr_ready') }}"
    data-payment-confirmed-label="{{ __('app.entrance_payment_confirmed') }}"
    data-request-error="{{ __('app.entrance_request_failed') }}"
    data-search-hint="{{ $searchHint }}"
    data-search-minimum-label="{{ __('app.entrance_guest_search_minimum') }}"
    data-searching-label="{{ __('app.entrance_searching') }}"
    data-no-guests-label="{{ $noPeopleLabel }}"
    data-no-tickets-label="{{ __('app.entrance_guest_has_no_tickets') }}"
    data-guest-fallback="{{ __('app.entrance_guest') }}"
    data-ticket-fallback="{{ __('app.entrance_ticket') }}"
    data-admit-label="{{ __('app.entrance_admit') }}"
    data-already-passed-label="{{ __('app.entrance_already_passed') }}"
    data-unavailable-label="{{ __('app.unavailable') }}"
>
    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <x-ui.button type="button" data-entrance-search-toggle aria-expanded="false" aria-controls="entrance-guest-search" variant="secondary" class="min-h-11 w-full sm:w-auto">
            <x-ui.icon name="search" class="h-4 w-4" />
            {{ $searchLabel }}
        </x-ui.button>
        @if ($canSell)
        <x-ui.button type="button" data-entrance-sale-open="cash" variant="success" class="min-h-11 w-full sm:w-auto" :disabled="$ticketTypes->isEmpty()">
            <x-ui.icon name="banknote" class="h-4 w-4" />
            {{ __('app.entrance_cash_ticket') }}
        </x-ui.button>
        <x-ui.button type="button" data-entrance-sale-open="card" class="min-h-11 w-full sm:w-auto" :disabled="$ticketTypes->isEmpty() || $paymentProviders->isEmpty()">
            <x-ui.icon name="credit-card" class="h-4 w-4" />
            {{ __('app.entrance_card_ticket') }}
        </x-ui.button>
        @endif
    </div>

    <div id="entrance-guest-search" class="mt-4 hidden border-t border-stone-100 pt-4" data-entrance-search-panel>
        <div class="flex items-start gap-2">
            <label class="min-w-0 flex-1">
                <span class="sr-only">{{ $searchLabel }}</span>
                <span class="relative block">
                    <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        autocomplete="off"
                        inputmode="search"
                        class="crm-field mt-0 min-h-12 pl-10 text-base"
                        placeholder="{{ $searchPlaceholder }}"
                        data-entrance-search-input
                    >
                </span>
            </label>
            <x-ui.button type="button" variant="ghost" icon-only aria-label="{{ __('app.close') }}" data-entrance-search-close>
                <x-ui.icon name="x" class="h-5 w-5" />
            </x-ui.button>
        </div>
        <p class="mt-2 min-h-5 text-xs text-slate-500" aria-live="polite" data-entrance-search-status>{{ $searchHint }}</p>
        <div class="mt-2 grid max-h-[min(28rem,55dvh)] gap-2 overflow-y-auto overscroll-contain" data-entrance-search-results></div>
    </div>

    @if ($canSell)
    <div class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="entrance-sale-title" data-entrance-sale-modal>
        <div class="max-h-[calc(100dvh-2rem)] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-6" data-entrance-sale-panel>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ __('app.entrance_door_sale') }}</p>
                    <h2 id="entrance-sale-title" class="mt-1 text-xl font-semibold text-slate-950" data-entrance-sale-title></h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.entrance_sale_fast_help') }}</p>
                </div>
                <button type="button" class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-950 crm-focus" aria-label="{{ __('app.close') }}" data-entrance-modal-dismiss><x-ui.icon name="x" class="h-5 w-5" /></button>
            </div>

            <form class="mt-5 space-y-4" data-entrance-sale-form>
                <label class="block">
                    <span class="crm-label">{{ __('app.person_name') }}</span>
                    <input name="guest_name" required maxlength="160" autocomplete="name" class="crm-field min-h-12 text-base">
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.email') }} <span class="font-normal text-slate-400">({{ __('app.optional') }})</span></span>
                    <input type="email" name="guest_email" maxlength="255" autocomplete="email" class="crm-field min-h-12 text-base">
                </label>
                <input type="hidden" name="terms_accepted" value="1">
                <label class="block">
                    <span class="crm-label">{{ __('app.entrance_ticket_type') }}</span>
                    <select name="ticket_type_id" required class="crm-field min-h-12 text-base">
                        @foreach ($ticketTypes as $ticketType)
                            @php
                                $ticketTypeId = data_get($ticketType, 'id');
                                $ticketTypeName = data_get($ticketType, 'name');
                                $ticketTypePrice = data_get($ticketType, 'price_label');
                                $ticketTypeRemaining = data_get($ticketType, 'remaining');
                            @endphp
                            <option value="{{ $ticketTypeId }}">
                                {{ $ticketTypeName }}@if($ticketTypePrice) · {{ $ticketTypePrice }}@endif @if($ticketTypeRemaining !== null) · {{ __('app.entrance_remaining_short', ['count' => $ticketTypeRemaining]) }}@endif
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="block hidden" data-entrance-sale-provider-field>
                    <span class="crm-label">{{ __('app.payment_method') }}</span>
                    <select name="provider" class="crm-field min-h-12 text-base" data-entrance-sale-provider>
                        @foreach ($paymentProviders as $provider)
                            @php
                                $providerValue = data_get($provider, 'value', data_get($provider, 'provider', $provider instanceof \BackedEnum ? $provider->value : $provider));
                                $providerLabel = data_get($provider, 'label', config('integrations.providers.'.$providerValue.'.label', $providerValue));
                            @endphp
                            <option value="{{ $providerValue }}">{{ $providerLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <p class="hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert" data-entrance-sale-error></p>
                <x-ui.button type="submit" size="lg" class="w-full min-h-12"><span data-entrance-sale-submit-label></span></x-ui.button>
            </form>

            <div class="mt-5 hidden" data-entrance-sale-result>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-950">
                    <div class="flex items-start gap-3">
                        <x-ui.icon name="circle-check" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-700" />
                        <p class="text-sm font-semibold leading-6" data-entrance-sale-result-message></p>
                    </div>
                </div>
                <div class="mt-4 hidden rounded-2xl border border-stone-200 bg-slate-50 p-4 text-center" data-entrance-sale-payment>
                    <img src="" alt="{{ __('app.entrance_payment_qr_alt') }}" class="mx-auto hidden aspect-square w-full max-w-64 rounded-xl bg-white p-3" data-entrance-sale-payment-qr>
                    <p class="mt-3 text-sm text-slate-600">{{ __('app.entrance_payment_qr_help') }}</p>
                    <x-ui.button href="#" target="_blank" rel="noopener" variant="secondary" class="mt-3 hidden" data-entrance-sale-payment-link><x-ui.icon name="external-link" class="h-4 w-4" />{{ __('app.entrance_open_payment') }}</x-ui.button>
                </div>
                <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-ui.button type="button" variant="secondary" data-entrance-modal-dismiss>{{ __('app.close') }}</x-ui.button>
                    <x-ui.button type="button" variant="success" class="hidden min-h-11" data-entrance-sale-admit><x-ui.icon name="ticket-check" class="h-4 w-4" />{{ __('app.entrance_admit_guest') }}</x-ui.button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="entrance-undo-title" data-entrance-undo-modal>
        <div class="w-full max-w-md rounded-2xl border-t-4 border-rose-400 bg-white p-5 shadow-2xl sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-700"><x-ui.icon name="undo-2" class="h-5 w-5" /></span>
                    <div><h2 id="entrance-undo-title" class="text-xl font-semibold text-slate-950">{{ __('app.entrance_undo_admission_title') }}</h2><p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.entrance_undo_admission_help') }}</p></div>
                </div>
                <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 crm-focus" aria-label="{{ __('app.close') }}" data-entrance-modal-dismiss><x-ui.icon name="x" class="h-5 w-5" /></button>
            </div>
            <p class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-900" data-entrance-undo-ticket></p>
            <form class="mt-4" data-entrance-undo-form>
                <label class="block"><span class="crm-label">{{ __('app.reason') }}</span><input name="reason" required maxlength="1000" autocomplete="off" class="crm-field min-h-12" placeholder="{{ __('app.entrance_undo_reason_placeholder') }}"></label>
                <p class="mt-3 hidden rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert" data-entrance-undo-error></p>
                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><x-ui.button type="button" variant="secondary" data-entrance-modal-dismiss>{{ __('app.cancel') }}</x-ui.button><x-ui.button type="submit" variant="danger">{{ __('app.entrance_confirm_undo') }}</x-ui.button></div>
            </form>
        </div>
    </div>
</section>
