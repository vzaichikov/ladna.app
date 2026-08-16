@extends('layouts.public')

@section('title', $event->title.' - '.$account->name)
@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection
@section('meta')
    <meta property="og:title" content="{{ $event->title }}">
    <meta property="og:description" content="{{ $event->summary ?: $account->name }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('public.events.show', [$account->slug, $event->slug]) }}">
    @if ($event->media->firstWhere('is_cover', true)?->imageUrl())<meta property="og:image" content="{{ url($event->media->firstWhere('is_cover', true)->imageUrl()) }}">@endif
@endsection

@section('content')
@php
    $cover = $event->media->firstWhere('is_cover', true);
    $venue = $event->venue_kind->value === 'studio'
        ? collect([$event->location?->name, $event->location?->address, $event->rooms->pluck('name')->join(', ')])->filter()->join(' · ')
        : collect([$event->external_venue_name, $event->external_address])->filter()->join(' · ');
    $oldItems = collect(old('items', []));
    $hasPaidTicketOptions = $checkoutTicketTypes->contains(fn (array $ticketType): bool => $ticketType['regular_price_cents'] > 0 || ($ticketType['early_bird_price_cents'] ?? 0) > 0);
    $eventRulesUrl = route('public.events.show', [$account->slug, $event->slug]).'#event-rules';
    $studioOfferUrl = route('public.studio-offer', ['accountSlug' => $account->slug, 'return_to' => request()->fullUrl()]);
@endphp
<main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
    <section class="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
        <x-ui.public-studio-header :account="$account" class="mb-6" />

        <header class="overflow-hidden rounded-3xl border border-stone-200 bg-white shadow-crm">
            @if ($cover)<img src="{{ $cover->imageUrl() }}" alt="{{ $cover->alt_text ?: $event->title }}" class="max-h-[34rem] w-full object-cover">@endif
            <div class="grid gap-8 p-6 sm:p-10 lg:grid-cols-[1fr_20rem]">
                <div>
                    @if ($event->status === \App\Enums\EventStatus::Cancelled)<span class="inline-flex rounded-full bg-rose-100 px-3 py-1 text-sm font-semibold text-rose-800">{{ __('app.event_cancelled_public') }}</span>@endif
                    <h1 class="mt-3 text-4xl font-semibold leading-tight sm:text-6xl">{{ $event->title }}</h1>
                    @if ($event->summary)<p class="mt-5 max-w-3xl text-lg leading-8 text-slate-600">{{ $event->summary }}</p>@endif
                </div>
                <aside class="rounded-2xl bg-brand-50 p-5 text-brand-950">
                    <p class="font-semibold">{{ $event->starts_at->timezone($event->timezone)->format('d.m.Y') }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $event->starts_at->timezone($event->timezone)->format('H:i') }}–{{ $event->ends_at->timezone($event->timezone)->format('H:i') }}</p>
                    <p class="mt-4 text-sm leading-6">{{ $venue }}</p>
                    @if ($event->external_map_url)<a href="{{ $event->external_map_url }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-block text-sm font-semibold underline">{{ __('app.event_open_map') }}</a>@endif
                    @if ($event->external_directions)<p class="mt-3 whitespace-pre-line text-sm leading-6">{{ $event->external_directions }}</p>@endif
                    @if ($hasPurchasableTickets)
                        <x-ui.button :href="'#buy-tickets'" size="lg" class="mt-5 w-full lg:hidden">
                            <x-ui.icon name="ticket" class="h-5 w-5" />
                            {{ __('app.event_buy_tickets') }}
                        </x-ui.button>
                    @endif
                </aside>
            </div>
        </header>

        <div class="mt-8 space-y-8">
            @if ($event->description_html)<article class="prose prose-slate max-w-none rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8">{!! $event->description_html !!}</article>@endif
            @if ($event->media->where('kind', 'image')->where('is_cover', false)->isNotEmpty())
                <div class="grid gap-4 sm:grid-cols-2">@foreach ($event->media->where('kind', 'image')->where('is_cover', false) as $media)<img src="{{ $media->imageUrl() }}" alt="{{ $media->alt_text ?: $event->title }}" class="aspect-[4/3] w-full rounded-2xl object-cover shadow-crm">@endforeach</div>
            @endif
            @foreach ($event->media->where('kind', 'video') as $media)
                @if ($media->embedUrl())<div class="aspect-video overflow-hidden rounded-2xl bg-slate-950 shadow-crm"><iframe src="{{ $media->embedUrl() }}" title="{{ $event->title }}" class="h-full w-full" loading="lazy" allow="fullscreen; picture-in-picture" allowfullscreen></iframe></div>@endif
            @endforeach
            @if ($event->rules_html)<article id="event-rules" class="scroll-mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8"><h2 class="text-2xl font-semibold">{{ __('app.event_rules') }}</h2><div class="prose prose-slate mt-4 max-w-none">{!! $event->rules_html !!}</div></article>@endif
        </div>

        <section id="buy-tickets" class="mt-8 scroll-mt-6">
            @if ($event->status === \App\Enums\EventStatus::Cancelled)
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm font-semibold text-rose-900 shadow-crm">{{ __('app.event_cancelled_sales_closed') }}</div>
            @elseif ($event->starts_at->isPast())
                <div class="rounded-2xl border border-stone-200 bg-white p-6 text-sm font-semibold text-slate-700 shadow-crm">{{ __('app.event_sales_closed') }}</div>
            @else
                <form
                    method="POST"
                    action="{{ route('public.events.checkout', [$account->slug, $event->slug]) }}"
                    class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.72fr)]"
                    data-event-ticket-checkout
                    data-currency="{{ $event->currency }}"
                    data-locale="{{ str_replace('_', '-', app()->getLocale()) }}"
                    data-event-capacity="{{ $eventRemainingCapacity }}"
                    data-event-has-paid-ticket-options="{{ $hasPaidTicketOptions ? 'true' : 'false' }}"
                >
                    @csrf
                    <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-7">
                        <h2 class="text-2xl font-semibold">{{ __('app.event_choose_tickets') }}</h2>
                        @if ($eventRemainingCapacity !== null)
                            <p class="mt-2 text-sm text-slate-500">{{ __('app.event_remaining_capacity', ['count' => $eventRemainingCapacity]) }}</p>
                        @endif

                        @if ($errors->any())
                            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                                <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                            </div>
                        @endif

                        <div class="mt-5 grid gap-3">
                            @foreach ($checkoutTicketTypes as $ticketType)
                                @php
                                    $oldQuantity = $oldItems->get($ticketType['id']);
                                    if ($oldQuantity === null) {
                                        $oldQuantity = data_get($oldItems->firstWhere('ticket_type_id', $ticketType['id']), 'quantity', 0);
                                    }
                                    $quantity = min(max((int) $oldQuantity, 0), $ticketType['max_quantity']);
                                    $ticketAvailable = $ticketType['sales_open'] && $ticketType['max_quantity'] > 0;
                                @endphp
                                <article
                                    class="grid gap-4 rounded-xl border border-stone-200 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                                    data-event-ticket-counter
                                    data-price-cents="{{ $ticketType['price_cents'] }}"
                                    data-regular-price-cents="{{ $ticketType['regular_price_cents'] }}"
                                    data-early-bird-price-cents="{{ $ticketType['early_bird_price_cents'] }}"
                                    data-early-bird-max-quantity="{{ $ticketType['early_bird_max_quantity'] }}"
                                    data-max-quantity="{{ $ticketType['max_quantity'] }}"
                                >
                                    <div class="min-w-0">
                                        <h3 class="font-semibold text-slate-950">{{ $ticketType['name'] }}</h3>
                                        @if ($ticketType['description'])<p class="mt-1 text-sm leading-6 text-slate-500">{{ $ticketType['description'] }}</p>@endif
                                        <p class="mt-2 text-sm font-semibold text-slate-700">
                                            {{ \App\Support\MoneyFormatter::format($ticketType['price_cents'], $event->currency) }}
                                            @if ($ticketType['early_bird_available'])<span class="font-medium text-emerald-700"> · {{ __('app.event_early_bird') }}</span>@endif
                                            <span class="font-medium text-slate-500"> · {{ __('app.event_ticket_remaining_count', ['count' => $ticketType['remaining_quantity']]) }}</span>
                                        </p>
                                        @if (! $ticketType['sales_open'])<p class="mt-1 text-xs font-semibold text-amber-700">{{ __('app.event_sales_window_closed') }}</p>@endif
                                        @if ($ticketType['remaining_quantity'] === 0)<p class="mt-1 text-xs font-semibold text-rose-700">{{ __('app.event_sold_out') }}</p>@endif
                                    </div>
                                    <div class="flex items-center justify-self-start rounded-xl border border-stone-200 bg-slate-50 p-1 sm:justify-self-end">
                                        <button
                                            type="button"
                                            class="flex h-11 w-11 items-center justify-center rounded-lg text-slate-700 transition hover:bg-white hover:text-slate-950 disabled:pointer-events-none disabled:opacity-35 crm-focus"
                                            data-event-ticket-decrement
                                            aria-label="{{ __('app.event_ticket_quantity_decrease', ['ticket' => $ticketType['name']]) }}"
                                            @disabled(! $ticketAvailable || $quantity === 0)
                                        >
                                            <x-ui.icon name="minus" class="h-5 w-5" />
                                        </button>
                                        <output class="min-w-11 px-2 text-center text-lg font-semibold tabular-nums" data-event-ticket-count aria-live="polite">{{ $quantity }}</output>
                                        <button
                                            type="button"
                                            class="flex h-11 w-11 items-center justify-center rounded-lg text-slate-700 transition hover:bg-white hover:text-slate-950 disabled:pointer-events-none disabled:opacity-35 crm-focus"
                                            data-event-ticket-increment
                                            aria-label="{{ __('app.event_ticket_quantity_increase', ['ticket' => $ticketType['name']]) }}"
                                            @disabled(! $ticketAvailable || $quantity >= $ticketType['max_quantity'])
                                        >
                                            <x-ui.icon name="plus" class="h-5 w-5" />
                                        </button>
                                        <input type="hidden" name="items[{{ $ticketType['id'] }}]" value="{{ $quantity }}" data-event-ticket-quantity @disabled(! $ticketAvailable)>
                                    </div>
                                </article>
                            @endforeach
                        </div>

                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <label class="block sm:col-span-2">
                                <span class="crm-label">{{ __('app.person_name') }}</span>
                                <input name="buyer_name" value="{{ old('buyer_name') }}" required autocomplete="name" class="crm-field">
                                <x-ui.field-error name="buyer_name" />
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.email') }}</span>
                                <input type="email" name="buyer_email" value="{{ old('buyer_email') }}" required autocomplete="email" class="crm-field">
                                <x-ui.field-error name="buyer_email" />
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.event_email_confirmation') }}</span>
                                <input type="email" name="buyer_email_confirmation" value="{{ old('buyer_email_confirmation') }}" required autocomplete="email" class="crm-field">
                                <x-ui.field-error name="buyer_email_confirmation" />
                            </label>
                            <p class="rounded-xl bg-brand-50 px-4 py-3 text-sm leading-6 text-slate-700 sm:col-span-2">
                                <span class="font-semibold">{{ __('app.event_ticket_email_delivery_title') }}</span>
                                {{ __('app.event_ticket_email_delivery_help') }}
                            </p>
                            @if ($googleEmailPrefillAvailable)
                                <div class="sm:col-span-2">
                                    <x-ui.button type="submit" variant="secondary" class="w-full" formaction="{{ route('public.events.checkout.google', [$account->slug, $event->slug]) }}" formmethod="POST" formnovalidate>
                                        <x-ui.icon name="mail" class="h-4 w-4" />
                                        {{ __('app.event_prefill_email_with_google') }}
                                    </x-ui.button>
                                    @error('google')<span class="crm-help mt-2 block">{{ $message }}</span>@enderror
                                </div>
                            @endif
                            <label class="block sm:col-span-2">
                                <span class="crm-label">{{ __('app.phone') }}</span>
                                <input type="tel" name="buyer_phone" value="{{ old('buyer_phone') }}" required autocomplete="tel" class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}" data-phone-mask-reject-national-zero data-phone-mask-national-zero-error="{{ __('app.event_phone_national_zero_error') }}">
                                <x-ui.field-error name="buyer_phone" />
                            </label>
                        </div>
                    </div>

                    <aside class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm lg:sticky lg:top-6 lg:self-start">
                        <h2 class="text-lg font-semibold">{{ __('app.payment_method') }}</h2>

                        <dl class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-4 text-sm">
                            <div>
                                <dt class="text-slate-500">{{ __('app.event_selected_tickets') }}</dt>
                                <dd class="mt-1 text-lg font-semibold tabular-nums" data-event-selected-count>0</dd>
                            </div>
                            <div class="text-right">
                                <dt class="text-slate-500">{{ __('app.total') }}</dt>
                                <dd class="mt-1 text-lg font-semibold tabular-nums" data-event-selected-total>—</dd>
                            </div>
                        </dl>

                        <label class="mt-5 flex items-start gap-3 rounded-xl border border-stone-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">
                            <input type="checkbox" name="accept_terms" value="1" required @checked(old('accept_terms', '1')) class="crm-checkbox mt-1">
                            <span>
                                {{ __('app.event_terms_acceptance_prefix') }}
                                <a href="{{ $eventRulesUrl }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 transition hover:text-brand-600">{{ __('app.event_rules_link_text') }}</a>
                                {{ __('app.event_terms_acceptance_between') }}
                                <a href="{{ $studioOfferUrl }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 transition hover:text-brand-600" data-public-legal-link>{{ __('app.event_offer_link_text') }}</a>.
                            </span>
                        </label>
                        <x-ui.field-error name="accept_terms" class="mt-2" />

                        <div class="mt-5 {{ $hasPaidTicketOptions ? 'hidden' : '' }}" data-event-payment-no-selection>
                            <p class="mb-3 text-sm font-semibold text-amber-800" data-event-payment-select-help aria-live="polite">{{ __('app.event_select_tickets_to_continue') }}</p>
                            <x-ui.button type="button" variant="success" size="lg" class="w-full" disabled>
                                <x-ui.icon name="ticket-check" class="h-5 w-5" />
                                {{ __('app.event_get_tickets') }}
                            </x-ui.button>
                        </div>

                        <div class="mt-5 hidden" data-event-payment-free>
                            <p class="mb-3 hidden text-sm font-semibold text-amber-800" data-event-payment-required-help="free" aria-live="polite">{{ __('app.event_complete_required_fields_first') }}</p>
                            <x-ui.button type="submit" variant="success" size="lg" class="w-full" data-event-free-action disabled>
                                <x-ui.icon name="ticket-check" class="h-5 w-5" />
                                {{ __('app.event_get_tickets') }}
                            </x-ui.button>
                        </div>

                        <div class="mt-5 {{ $hasPaidTicketOptions ? '' : 'hidden' }}" data-event-payment-paid>
                            <p class="mb-3 text-sm font-semibold text-amber-800" data-event-payment-select-help aria-live="polite">{{ __('app.event_select_tickets_to_continue') }}</p>
                            @if ($paymentSettings->isNotEmpty())
                                <p class="mb-3 hidden text-sm font-semibold text-amber-800" data-event-payment-required-help="paid" aria-live="polite">{{ __('app.event_complete_required_fields_first') }}</p>
                                <div class="space-y-3">
                                    @foreach ($paymentSettings as $setting)
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

        <x-ui.public-contact-links :account="$account" class="mt-8" />
    </section>
</main>
@endsection
