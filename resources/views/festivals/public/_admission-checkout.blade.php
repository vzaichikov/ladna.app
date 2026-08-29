@php
    $oldItems = collect(old('items', []));
    $festivalCheckoutPrefill = $festivalCheckoutPrefill ?? [];
    $hasPaidTicketOptions = $festivalAdmissionOptions->contains(fn (array $option): bool => $option['regular_price_cents'] > 0 || ($option['early_bird_price_cents'] ?? 0) > 0);
    $festivalRulesUrl = route('public.festivals.show', [$account->slug, $edition->slug]).'#festival-rules';
    $studioOfferUrl = route('public.studio-offer', ['accountSlug' => $account->slug, 'return_to' => request()->fullUrl()]);
@endphp

<section id="festival-admission" class="mt-8 scroll-mt-6" data-festival-admission-checkout>
    @if ($festivalAdmissionOptions->isEmpty())
        <div class="rounded-2xl border festival-border festival-surface p-6 text-sm font-semibold festival-muted shadow-crm">
            {{ __('app.festival_admission_unavailable') }}
        </div>
    @else
        <form
            method="POST"
            action="{{ route('public.festivals.admission.store', [$account->slug, $edition->slug]) }}"
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.72fr)]"
            data-event-ticket-checkout
            data-festival-ticket-checkout
            data-currency="{{ $account->default_currency }}"
            data-locale="{{ str_replace('_', '-', app()->getLocale()) }}"
            data-event-capacity=""
            data-event-has-paid-ticket-options="{{ $hasPaidTicketOptions ? 'true' : 'false' }}"
        >
            @csrf
            @if ($festivalFriendPurchase ?? false)
                <input type="hidden" name="friends" value="1">
            @endif
            <div class="rounded-2xl border festival-border festival-surface p-5 shadow-crm sm:p-7">
                <h2 class="text-2xl font-semibold festival-text">{{ __('app.event_choose_tickets') }}</h2>

                @if ($errors->any())
                    <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-5 grid gap-3">
                    @foreach ($festivalAdmissionOptions as $option)
                        @php
                            $oldQuantity = $oldItems->get($option['id']);
                            if ($oldQuantity === null) {
                                $oldQuantity = data_get($oldItems->firstWhere('admission_type_id', $option['id']), 'quantity', 0);
                            }
                            $quantity = min(max((int) $oldQuantity, 0), $option['max_quantity']);
                            $ticketAvailable = $option['sales_open'] && $option['max_quantity'] > 0;
                        @endphp
                        <article
                            class="grid gap-4 rounded-xl border festival-border p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                            data-event-ticket-counter
                            data-festival-exclusive-ticket="{{ $option['exclusive'] ? 'true' : 'false' }}"
                            data-price-cents="{{ $option['price_cents'] }}"
                            data-regular-price-cents="{{ $option['regular_price_cents'] }}"
                            data-early-bird-price-cents="{{ $option['early_bird_price_cents'] }}"
                            data-early-bird-max-quantity="{{ $option['early_bird_max_quantity'] }}"
                            data-max-quantity="{{ $option['max_quantity'] }}"
                        >
                            <div class="min-w-0">
                                <h3 class="font-semibold festival-text">{{ $option['name'] }}</h3>
                                @if ($option['description'])
                                    <p class="mt-1 text-sm leading-6 festival-muted">{{ $option['description'] }}</p>
                                @endif
                                <p class="mt-2 text-sm font-semibold festival-text">
                                    {{ \App\Support\MoneyFormatter::format($option['price_cents'], $account->default_currency) }}
                                    @if ($option['early_bird_available'])
                                        <span class="font-medium text-emerald-700"> · {{ __('app.event_early_bird') }}</span>
                                    @endif
                                    <span class="font-medium festival-muted"> · {{ __('app.event_ticket_remaining_count', ['count' => $option['remaining_quantity']]) }}</span>
                                </p>
                                @if (! $option['sales_open'])
                                    <p class="mt-1 text-xs font-semibold text-amber-700">{{ __('app.event_sales_window_closed') }}</p>
                                @elseif ($option['remaining_quantity'] === 0)
                                    <p class="mt-1 text-xs font-semibold text-rose-700">{{ __('app.event_sold_out') }}</p>
                                @endif
                            </div>
                            <div class="flex items-center justify-self-start rounded-xl border festival-border festival-page p-1 sm:justify-self-end">
                                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg transition disabled:pointer-events-none disabled:opacity-35 crm-focus" data-event-ticket-decrement aria-label="{{ __('app.event_ticket_quantity_decrease', ['ticket' => $option['name']]) }}" @disabled(! $ticketAvailable || $quantity === 0)>
                                    <x-ui.icon name="minus" class="h-5 w-5" />
                                </button>
                                <output class="min-w-11 px-2 text-center text-lg font-semibold tabular-nums" data-event-ticket-count aria-live="polite">{{ $quantity }}</output>
                                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg transition disabled:pointer-events-none disabled:opacity-35 crm-focus" data-event-ticket-increment aria-label="{{ __('app.event_ticket_quantity_increase', ['ticket' => $option['name']]) }}" @disabled(! $ticketAvailable || $quantity >= $option['max_quantity'])>
                                    <x-ui.icon name="plus" class="h-5 w-5" />
                                </button>
                                <input type="hidden" name="items[{{ $option['id'] }}]" value="{{ $quantity }}" data-event-ticket-quantity @disabled(! $ticketAvailable)>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <label class="block sm:col-span-2">
                        <span class="crm-label">{{ __('app.person_name') }}</span>
                        <input name="buyer_name" value="{{ old('buyer_name', data_get($festivalCheckoutPrefill, 'buyer_name')) }}" required autocomplete="name" class="crm-field">
                        <x-ui.field-error name="buyer_name" />
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.email') }}</span>
                        <input type="email" name="buyer_email" value="{{ old('buyer_email', data_get($festivalCheckoutPrefill, 'buyer_email')) }}" required autocomplete="email" class="crm-field">
                        <x-ui.field-error name="buyer_email" />
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_email_confirmation') }}</span>
                        <input type="email" name="buyer_email_confirmation" value="{{ old('buyer_email_confirmation', data_get($festivalCheckoutPrefill, 'buyer_email_confirmation')) }}" required autocomplete="email" class="crm-field">
                        <x-ui.field-error name="buyer_email_confirmation" />
                    </label>
                    <p class="rounded-xl bg-brand-50 px-4 py-3 text-sm leading-6 text-slate-700 sm:col-span-2">
                        <span class="font-semibold">{{ __('app.event_ticket_email_delivery_title') }}</span>
                        {{ __('app.event_ticket_email_delivery_help') }}
                    </p>
                    @if ($festivalGoogleEmailPrefillAvailable)
                        <div class="sm:col-span-2">
                            <x-ui.button type="submit" variant="secondary" class="w-full" formaction="{{ route('public.festivals.admission.google', [$account->slug, $edition->slug]) }}" formmethod="POST" formnovalidate>
                                <x-ui.icon name="mail" class="h-4 w-4" />
                                {{ __('app.event_prefill_email_with_google') }}
                            </x-ui.button>
                            @error('google')<span class="crm-help mt-2 block">{{ $message }}</span>@enderror
                        </div>
                    @endif
                    <label class="block sm:col-span-2">
                        <span class="crm-label">{{ __('app.phone') }}</span>
                        <input type="tel" name="buyer_phone" value="{{ old('buyer_phone', data_get($festivalCheckoutPrefill, 'buyer_phone')) }}" required autocomplete="tel" class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}" data-phone-mask-reject-national-zero data-phone-mask-national-zero-error="{{ __('app.event_phone_national_zero_error') }}">
                        <x-ui.field-error name="buyer_phone" />
                    </label>
                </div>
            </div>

            <aside class="rounded-2xl border festival-border festival-surface p-5 shadow-crm lg:sticky lg:top-6 lg:self-start">
                <h2 class="text-lg font-semibold festival-text">{{ __('app.payment_method') }}</h2>
                <dl class="mt-4 grid grid-cols-2 gap-3 rounded-xl festival-page p-4 text-sm">
                    <div>
                        <dt class="festival-muted">{{ __('app.event_selected_tickets') }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums" data-event-selected-count>0</dd>
                    </div>
                    <div class="text-right">
                        <dt class="festival-muted">{{ __('app.total') }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums" data-event-selected-total>—</dd>
                    </div>
                </dl>

                <label class="mt-5 flex items-start gap-3 rounded-xl border festival-border festival-page px-4 py-3 text-sm leading-6 festival-muted">
                    <input type="checkbox" name="terms" value="1" required @checked(old('terms', '1')) class="crm-checkbox mt-1">
                    <span>
                        {{ __('app.event_terms_acceptance_prefix') }}
                        <a href="{{ $festivalRulesUrl }}" target="_blank" rel="noopener" class="font-semibold festival-primary-text">{{ __('app.event_rules_link_text') }}</a>
                        {{ __('app.event_terms_acceptance_between') }}
                        <a href="{{ $studioOfferUrl }}" target="_blank" rel="noopener" class="font-semibold festival-primary-text" data-public-legal-link>{{ __('app.event_offer_link_text') }}</a>.
                    </span>
                </label>
                <x-ui.field-error name="terms" class="mt-2" />

                <div class="mt-5 {{ $hasPaidTicketOptions ? 'hidden' : '' }}" data-event-payment-no-selection>
                    <p class="mb-3 text-sm font-semibold text-amber-800" data-event-payment-select-help aria-live="polite">{{ __('app.event_select_tickets_to_continue') }}</p>
                    <x-ui.button type="button" variant="success" size="lg" class="w-full" disabled><x-ui.icon name="ticket-check" class="h-5 w-5" />{{ __('app.event_get_tickets') }}</x-ui.button>
                </div>
                <div class="mt-5 hidden" data-event-payment-free>
                    <p class="mb-3 hidden text-sm font-semibold text-amber-800" data-event-payment-required-help="free" aria-live="polite">{{ __('app.event_complete_required_fields_first') }}</p>
                    <x-ui.button type="submit" variant="success" size="lg" class="w-full" data-event-free-action disabled><x-ui.icon name="ticket-check" class="h-5 w-5" />{{ __('app.event_get_tickets') }}</x-ui.button>
                </div>
                <div class="mt-5 {{ $hasPaidTicketOptions ? '' : 'hidden' }}" data-event-payment-paid>
                    <p class="mb-3 text-sm font-semibold text-amber-800" data-event-payment-select-help aria-live="polite">{{ __('app.event_select_tickets_to_continue') }}</p>
                    @if ($festivalPaymentSettings->isNotEmpty())
                        <p class="mb-3 hidden text-sm font-semibold text-amber-800" data-event-payment-required-help="paid" aria-live="polite">{{ __('app.event_complete_required_fields_first') }}</p>
                        <div class="space-y-3">
                            @foreach ($festivalPaymentSettings as $setting)
                                @php
                                    $provider = $setting->provider->value;
                                    $providerLabel = config('integrations.providers.'.$provider.'.label', $provider);
                                @endphp
                                <x-ui.button type="submit" name="provider" value="{{ $provider }}" variant="success" size="lg" class="w-full justify-start px-3" data-event-paid-action disabled>
                                    <x-ui.payment-brand :provider="$provider" :label="$providerLabel" presentation="card" class="w-full" />
                                </x-ui.button>
                            @endforeach
                        </div>
                        <x-ui.accepted-card-brands class="mt-5" />
                    @else
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900" data-event-payment-unavailable>{{ __('app.no_payment_methods_available') }}</div>
                    @endif
                </div>
            </aside>
        </form>
    @endif
</section>
