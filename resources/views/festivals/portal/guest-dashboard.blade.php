@extends('layouts.festival-portal')

@section('title', __('app.festival_my_tickets').' - '.$account->name)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')
        <header class="mt-8">
            <h1 class="text-3xl font-semibold sm:text-4xl">{{ __('app.festival_my_tickets') }}</h1>
            <p class="mt-2 text-slate-600">{{ __('app.festival_guest_login_copy') }}</p>
        </header>

        @if(session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif

        @if($editions->isNotEmpty())
            <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <h2 class="text-xl font-semibold">{{ __('app.festival_admission') }}</h2>
                @if($errors->any())<div class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $errors->first() }}</div>@endif
                <div class="mt-5 grid gap-5 lg:grid-cols-2">
                    @foreach($editions as $edition)
                        <div class="rounded-xl border border-stone-200 p-4">
                            <h3 class="font-semibold">{{ $edition->title }}</h3>
                            <form method="POST" action="{{ route('public.festivals.admission.store', [$account->slug, $edition->slug]) }}" class="mt-4 space-y-3">
                                @csrf
                                <input type="hidden" name="buyer_name" value="{{ $portalUser->displayName() }}">
                                <input type="hidden" name="buyer_email" value="{{ $portalUser->email }}">
                                <input type="hidden" name="buyer_phone" value="{{ $portalUser->phone }}">
                                <label class="block"><span class="crm-label">{{ __('app.festival_tickets') }}</span><select name="items[0][admission_type_id]" class="crm-field" required>@foreach($edition->admissionTypes as $type)<option value="{{ $type->id }}">{{ $type->name }} — {{ \App\Support\MoneyFormatter::format($type->currentPrice()['price_cents'], $account->default_currency) }}</option>@endforeach</select></label>
                                <label class="block"><span class="crm-label">{{ __('app.event_ticket_quantity') }}</span><input type="number" name="items[0][quantity]" min="1" max="20" value="1" required class="crm-field"></label>
                                @if($providers->isNotEmpty())
                                    <label class="block"><span class="crm-label">{{ __('app.payment_method') }}</span><select name="provider" class="crm-field" required>@foreach($providers as $provider)<option value="{{ $provider->provider->value }}">{{ config('integrations.providers.'.$provider->provider->value.'.label') }}</option>@endforeach</select></label>
                                    <label class="flex items-start gap-2 text-sm text-slate-600"><input type="checkbox" name="terms" value="1" required class="crm-checkbox mt-0.5">{{ __('app.festival_admission_terms') }}</label>
                                    <x-ui.button type="submit" class="w-full">{{ __('app.pay') }}</x-ui.button>
                                @endif
                            </form>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="mt-6 grid gap-5">
            @forelse($orders as $order)
                <article class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
                    <div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                        <div>
                            <p class="text-sm font-semibold text-brand-700">{{ $order->edition->title }}</p>
                            <h2 class="mt-1 text-xl font-semibold">{{ $order->order_id }}</h2>
                            <p class="mt-2 text-sm text-slate-500">{{ __('app.status') }}: {{ __('app.festival_order_'.$order->status->value) }}</p>

                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach($order->tickets as $ticket)
                                    <section class="rounded-xl border border-stone-200 p-4">
                                        <div class="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <h3 class="font-semibold">{{ $ticket->admissionType->name }}</h3>
                                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $ticket->code }}</p>
                                            </div>
                                            <span class="crm-badge">{{ $ticket->admissionType->delivery_mode === \App\Enums\FestivalAdmissionDeliveryMode::OnlineStream ? __('app.festival_online_stream') : __('app.festival_venue_ticket') }}</span>
                                        </div>
                                        @if($ticket->admissionType->delivery_mode === \App\Enums\FestivalAdmissionDeliveryMode::OnlineStream)
                                            @php($entitlement = $ticket->streamEntitlement)
                                            @php($stream = $entitlement?->stream)
                                            @php($mediaStatus = $stream ? ($streamStatuses[$stream->id] ?? null) : null)
                                            <p class="mt-3 text-sm font-medium text-slate-600">
                                                @if($order->status === \App\Enums\FestivalTicketOrderStatus::Refunded || $ticket->status === \App\Enums\FestivalTicketStatus::Refunded) {{ __('app.festival_order_refunded') }}
                                                @elseif(! $entitlement || $ticket->status !== \App\Enums\FestivalTicketStatus::Valid || $order->status !== \App\Enums\FestivalTicketOrderStatus::Paid) {{ __('app.festival_stream_unavailable') }}
                                                @elseif(! $stream->is_enabled) {{ __('app.festival_stream_disabled') }}
                                                @elseif($stream->playback_override === \App\Enums\FestivalStreamOverride::Closed || $stream->closes_at?->isPast()) {{ __('app.festival_stream_closed') }}
                                                @elseif($stream->playback_override === \App\Enums\FestivalStreamOverride::Automatic && $stream->opens_at?->isFuture()) {{ __('app.festival_stream_not_open') }}
                                                @elseif($mediaStatus === null) {{ __('app.festival_stream_status_unavailable') }}
                                                @elseif($mediaStatus['publisher_online']) {{ __('app.festival_stream_live') }}
                                                @else {{ __('app.festival_stream_publisher_offline') }}
                                                @endif
                                            </p>
                                            @if($entitlement && $ticket->status === \App\Enums\FestivalTicketStatus::Valid && $order->status === \App\Enums\FestivalTicketOrderStatus::Paid)
                                                <div class="mt-4 flex flex-wrap gap-2">
                                                    <a href="{{ route('festival.portal.guest.stream.watch', [$account->slug, $entitlement]) }}" class="inline-flex min-h-10 items-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">{{ __('app.festival_watch_stream') }}</a>
                                                    <form method="POST" action="{{ route('festival.portal.guest.stream.release', [$account->slug, $entitlement]) }}">
                                                        @csrf @method('DELETE')
                                                        <button type="submit" class="inline-flex min-h-10 items-center rounded-lg border border-stone-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('app.festival_stream_release_devices') }}</button>
                                                    </form>
                                                </div>
                                            @endif
                                        @elseif($order->status === \App\Enums\FestivalTicketOrderStatus::Paid)
                                            <a href="{{ route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]) }}" class="mt-4 inline-flex text-sm font-semibold text-brand-700 underline">{{ __('app.festival_ticket_qr') }}</a>
                                        @endif
                                    </section>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <x-ui.empty-state :title="__('app.festival_guest_tickets_empty')" />
            @endforelse
        </div>
    </div>
</main>
@endsection
