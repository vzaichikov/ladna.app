@extends('layouts.festival-portal')

@section('title', __('app.festival_tickets_and_passes').' - '.$account->name)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')

        <header class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-3xl font-semibold sm:text-4xl">{{ __('app.festival_tickets_and_passes') }}</h1>
                <p class="mt-2 text-slate-600">{{ __('app.festival_tickets_and_passes_copy') }}</p>
            </div>
        </header>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>
        @endif

        <nav class="mt-6 flex flex-wrap gap-2" aria-label="{{ __('app.festival_tickets_and_passes') }}">
            <a href="{{ route('festival.portal.tickets.index', [$account->slug, 'tab' => 'passes']) }}" @class(['rounded-xl px-4 py-2 text-sm font-semibold crm-focus', 'bg-brand-700 text-white' => $activeTab === 'passes', 'border border-stone-200 bg-white text-slate-700' => $activeTab !== 'passes'])>{{ __('app.festival_my_passes') }}</a>
            <a href="{{ route('festival.portal.tickets.index', [$account->slug, 'tab' => 'friends']) }}" @class(['rounded-xl px-4 py-2 text-sm font-semibold crm-focus', 'bg-brand-700 text-white' => $activeTab === 'friends', 'border border-stone-200 bg-white text-slate-700' => $activeTab !== 'friends'])>{{ __('app.festival_friend_tickets') }}</a>
        </nav>

        @if ($activeTab === 'passes')
            <div class="mt-6 space-y-6">
                @forelse ($passes->groupBy('festival_edition_id') as $editionPasses)
                    @php
                        $edition = $editionPasses->first()->edition;
                        $usableEditionPasses = $editionPasses->whereIn('id', $usablePassIds);
                        $pdfUrl = route('festival.portal.tickets.passes.pdf', [$account->slug, $edition]);
                    @endphp
                    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-7" data-ticket-screen @if ($usableEditionPasses->isNotEmpty()) data-print-section @endif>
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-brand-700">{{ $edition->series->name }}</p>
                                <h2 class="mt-1 text-2xl font-semibold">{{ $edition->title }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ $edition->starts_at?->timezone($edition->timezone)->format('d.m.Y H:i') }} · {{ $edition->venue_name }}</p>
                            </div>
                            @if ($usableEditionPasses->isNotEmpty())
                                <div class="flex flex-wrap gap-2" data-print-screen-only>
                                    <x-ui.button type="button" variant="success" size="sm" data-ticket-pdf-share data-pdf-url="{{ $pdfUrl }}" data-pdf-filename="festival-passes-{{ $edition->slug }}.pdf" data-share-title="{{ $edition->title }}">
                                        <x-ui.icon name="share-2" class="h-4 w-4" />
                                        <span data-ticket-share-label data-default-label="{{ __('app.festival_order_share_pdf') }}" data-loading-label="{{ __('app.festival_order_preparing_pdf') }}">{{ __('app.festival_order_share_pdf') }}</span>
                                    </x-ui.button>
                                    <x-ui.button :href="$pdfUrl" variant="secondary" size="sm" download data-ticket-pdf-download><x-ui.icon name="download" class="h-4 w-4" />{{ __('app.festival_order_download_pdf') }}</x-ui.button>
                                    <x-ui.button type="button" variant="secondary" size="sm" data-print-button><x-ui.icon name="printer" class="h-4 w-4" />{{ __('app.festival_order_print_or_save') }}</x-ui.button>
                                    <form method="POST" action="{{ route('festival.portal.tickets.passes.email', [$account->slug, $edition]) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="secondary" size="sm"><x-ui.icon name="mail" class="h-4 w-4" />{{ __('app.festival_send_to_email') }}</x-ui.button>
                                    </form>
                                </div>
                            @endif
                        </div>
                        <p class="mt-3 hidden text-sm font-semibold text-rose-700" data-ticket-share-error aria-live="polite">{{ __('app.festival_order_share_pdf_failed') }}</p>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($editionPasses as $pass)
                                @php
                                    $usable = $usablePassIds->contains($pass->id);
                                    $isHelper = $pass->participant->member_type === \App\Enums\FestivalTeamMemberType::Helper;
                                    $label = $isHelper ? __('app.festival_helper_pass') : __('app.festival_participant_pass');
                                @endphp
                                <article @class(['rounded-xl border p-4 text-center', 'border-emerald-200 bg-emerald-50' => $usable, 'border-stone-200 bg-stone-50 opacity-75' => ! $usable])>
                                    <div class="flex items-start justify-between gap-2 text-left">
                                        <div class="min-w-0"><p class="truncate font-semibold">{{ $pass->participant->displayName() }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $label }}</p></div>
                                        <span @class(['shrink-0 rounded-full px-2 py-1 text-xs font-semibold', 'bg-emerald-100 text-emerald-800' => $usable, 'bg-stone-200 text-stone-700' => ! $usable])>{{ $usable ? __('app.festival_pass_active') : __('app.festival_pass_inactive') }}</span>
                                    </div>
                                    @if ($usable)
                                        <img src="{{ $qrCodes[$pass->id] }}" alt="{{ __('app.festival_ticket_qr') }}" class="mx-auto mt-4 h-40 w-40 max-w-full">
                                    @else
                                        <div class="mx-auto mt-4 flex h-40 w-40 max-w-full items-center justify-center rounded-xl bg-stone-200 text-stone-500"><x-ui.icon name="qr-code" class="h-12 w-12" /></div>
                                    @endif
                                    <p class="mt-3 font-mono text-sm font-semibold">{{ $pass->code }}</p>
                                </article>
                            @endforeach
                        </div>

                        @if ($usableEditionPasses->isNotEmpty())
                            <div class="hidden" data-ticket-print-pages>
                                @foreach ($usableEditionPasses as $pass)
                                    <article data-ticket-print-page>
                                        <p class="text-sm font-semibold">{{ $account->name }}</p>
                                        <h1 class="mt-3 text-3xl font-semibold">{{ $edition->title }}</h1>
                                        <p class="mt-8 text-lg font-semibold">{{ $pass->participant->displayName() }}</p>
                                        <p class="mt-1 text-sm">{{ $pass->participant->member_type === \App\Enums\FestivalTeamMemberType::Helper ? __('app.festival_helper_pass') : __('app.festival_participant_pass') }}</p>
                                        <img src="{{ $qrCodes[$pass->id] }}" alt="{{ __('app.festival_ticket_qr') }}" data-ticket-print-qr>
                                        <p class="mt-5 font-mono text-2xl font-semibold">{{ $pass->code }}</p>
                                        <p class="mt-3 text-sm">{{ __('app.festival_ticket_present_at_entrance') }}</p>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @empty
                    <x-ui.empty-state icon="qr-code">{{ __('app.festival_passes_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
        @else
            <section class="mt-6 space-y-4">
                <div class="flex justify-end">
                    <x-ui.button :href="route('festival.portal.dashboard', $account->slug)" variant="success"><x-ui.icon name="ticket-plus" class="h-4 w-4" />{{ __('app.festival_buy_tickets_for_friends') }}</x-ui.button>
                </div>
                @forelse ($friendOrders as $order)
                    <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-brand-700">{{ $order->edition->title }}</p>
                                <h2 class="mt-1 text-lg font-semibold">{{ $order->buyer_name }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ $order->buyer_email }} · {{ $order->order_id }} · {{ trans_choice('app.festival_friend_ticket_count', $order->tickets->count(), ['count' => $order->tickets->count()]) }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_order_'.$order->status->value) }}</span>
                                <x-ui.button :href="route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted])" variant="secondary" size="sm">{{ __('app.festival_open_order') }}</x-ui.button>
                            </div>
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state icon="tickets">{{ __('app.festival_friend_tickets_empty') }}</x-ui.empty-state>
                @endforelse
            </section>
        @endif
    </div>
</main>
@endsection
