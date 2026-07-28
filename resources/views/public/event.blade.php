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
    $eventRemaining = $event->remainingCapacity();
    $venue = $event->venue_kind->value === 'studio'
        ? collect([$event->location?->name, $event->location?->address, $event->rooms->pluck('name')->join(', ')])->filter()->join(' · ')
        : collect([$event->external_venue_name, $event->external_address])->filter()->join(' · ');
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
                </aside>
            </div>
        </header>

        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_24rem]">
            <div class="space-y-8">
                @if ($event->description_html)<article class="prose prose-slate max-w-none rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8">{!! $event->description_html !!}</article>@endif
                @if ($event->media->where('kind', 'image')->where('is_cover', false)->isNotEmpty())
                    <div class="grid gap-4 sm:grid-cols-2">@foreach ($event->media->where('kind', 'image')->where('is_cover', false) as $media)<img src="{{ $media->imageUrl() }}" alt="{{ $media->alt_text ?: $event->title }}" class="aspect-[4/3] w-full rounded-2xl object-cover shadow-crm">@endforeach</div>
                @endif
                @foreach ($event->media->where('kind', 'video') as $media)
                    @if ($media->embedUrl())<div class="aspect-video overflow-hidden rounded-2xl bg-slate-950 shadow-crm"><iframe src="{{ $media->embedUrl() }}" title="{{ $event->title }}" class="h-full w-full" loading="lazy" allow="fullscreen; picture-in-picture" allowfullscreen></iframe></div>@endif
                @endforeach
                @if ($event->rules_html)<article class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8"><h2 class="text-2xl font-semibold">{{ __('app.event_rules') }}</h2><div class="prose prose-slate mt-4 max-w-none">{!! $event->rules_html !!}</div></article>@endif
            </div>

            <aside class="lg:sticky lg:top-6 lg:self-start">
                <form method="POST" action="{{ route('public.events.checkout', [$account->slug, $event->slug]) }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                    @csrf
                    <h2 class="text-2xl font-semibold">{{ __('app.event_choose_tickets') }}</h2>
                    @if ($errors->any())
                        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"><ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                    @endif
                    @if ($event->status === \App\Enums\EventStatus::Cancelled)
                        <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ __('app.event_cancelled_sales_closed') }}</p>
                    @elseif ($event->starts_at->isPast())
                        <p class="mt-4 rounded-xl bg-slate-100 p-4 text-sm text-slate-700">{{ __('app.event_sales_closed') }}</p>
                    @else
                        <div class="mt-5 space-y-3">
                            @foreach ($event->ticketTypes as $type)
                                @php
                                    $remaining = min($type->remainingQuantity(), $eventRemaining ?? PHP_INT_MAX);
                                    $salesOpen = $type->salesAreOpen();
                                @endphp
                                <label class="grid grid-cols-[1fr_5rem] items-center gap-3 rounded-xl border border-stone-200 p-4 transition focus-within:border-brand-500 focus-within:ring-3 focus-within:ring-violet-crm-100">
                                    <span>
                                        <strong class="block">{{ $type->name }}</strong>
                                        <span class="mt-1 block text-sm text-slate-500">
                                            {{ number_format($type->currentPriceCents() / 100, 2) }} {{ $event->currency }}
                                            @if ($type->earlyBirdIsAvailableFor()) · {{ __('app.event_early_bird') }}@endif
                                            · {{ $remaining }} {{ __('app.event_left') }}
                                        </span>
                                        @if (! $salesOpen)<span class="mt-1 block text-xs font-semibold text-amber-700">{{ __('app.event_sales_window_closed') }}</span>@endif
                                        @if ($remaining === 0)<span class="mt-1 block text-xs font-semibold text-rose-700">{{ __('app.event_sold_out') }}</span>@endif
                                    </span>
                                    <input type="number" name="items[{{ $type->id }}]" min="0" max="{{ min($type->max_per_order, $remaining) }}" value="{{ old('items.'.$type->id, 0) }}" class="crm-field mt-0 text-center" @disabled(! $salesOpen || $remaining === 0)>
                                </label>
                            @endforeach
                        </div>
                        <div class="mt-5 grid gap-3">
                            <label class="block">
                                <span class="crm-label">{{ __('app.person_name') }}</span>
                                <input name="buyer_name" value="{{ old('buyer_name') }}" required autocomplete="name" class="crm-field">
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.email') }}</span>
                                <input type="email" name="buyer_email" value="{{ old('buyer_email') }}" required autocomplete="email" class="crm-field">
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.phone_optional') }}</span>
                                <input name="buyer_phone" value="{{ old('buyer_phone') }}" autocomplete="tel" class="crm-field">
                            </label>
                        </div>
                        @if ($paymentSettings->isNotEmpty())
                            <div class="mt-5 space-y-2">@foreach ($paymentSettings as $setting)<label class="flex cursor-pointer items-center gap-3 rounded-xl border border-stone-200 p-3 transition hover:border-brand-100 has-checked:border-brand-600 has-checked:bg-brand-50 has-checked:ring-1 has-checked:ring-brand-600"><input type="radio" name="provider" value="{{ $setting->provider->value }}" @checked($loop->first) class="crm-radio"><x-ui.payment-brand :provider="$setting->provider->value" :label="config('integrations.providers.'.$setting->provider->value.'.label')" class="flex-1" /></label>@endforeach</div>
                        @endif
                        <label class="mt-5 flex items-start gap-3 text-sm leading-5 text-slate-600"><input type="checkbox" name="accept_terms" value="1" required class="crm-checkbox mt-0.5"><span>{{ __('app.event_terms_acceptance') }}</span></label>
                        <x-ui.button type="submit" size="lg" class="mt-5 w-full">{{ __('app.event_checkout') }}</x-ui.button>
                    @endif
                </form>
            </aside>
        </div>
        <x-ui.public-contact-links :account="$account" class="mt-8" />
    </section>
</main>
@endsection
