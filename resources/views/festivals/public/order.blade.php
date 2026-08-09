@extends('layouts.public')

@section('title', __('app.festival_tickets').' - '.$order->edition->title)

@section('content')
<main class="min-h-screen bg-canvas px-5 py-10"><div class="mx-auto max-w-4xl"><x-ui.public-studio-header :account="$account" /><header class="mt-8"><h1 class="text-4xl font-semibold">{{ __('app.festival_tickets') }}</h1><p class="mt-2 text-slate-600">{{ $order->edition->title }} · {{ $order->order_id }}</p></header>
    <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-crm"><p class="text-sm text-slate-500">{{ __('app.status') }}</p><strong class="mt-1 block text-xl">{{ __('app.festival_order_'.$order->status->value) }}</strong>@if($order->status === \App\Enums\FestivalTicketOrderStatus::Paid)<div class="mt-6 grid gap-5 sm:grid-cols-2">@foreach($order->tickets as $ticket)<article class="rounded-2xl border border-stone-200 p-5 text-center"><h2 class="text-lg font-semibold">{{ $ticket->admissionType->name }}</h2><p class="mt-1 font-mono text-sm">{{ $ticket->code }}</p><img src="{{ $qrCodes[$ticket->id] }}" alt="{{ __('app.festival_ticket_qr') }}" class="mx-auto mt-4 w-full max-w-64"><a href="{{ route('public.festival-tickets.qr', [$account->slug, $order->access_token_encrypted, $ticket->code]) }}" class="mt-3 inline-block text-sm font-semibold text-brand-700 underline">{{ __('app.download') }}</a></article>@endforeach</div>@elseif($order->status === \App\Enums\FestivalTicketOrderStatus::PaidRequiresRefund)<p class="mt-4 rounded-xl bg-amber-50 p-4 text-amber-900">{{ __('app.festival_order_refund_required') }}</p>@else<p class="mt-4 text-slate-600">{{ __('app.festival_order_pending_copy') }}</p>@endif</section>
</div></main>
@endsection
