@extends('layouts.app')

@section('title', __('app.festivals').' - '.$account->name)

@section('content')
<div class="space-y-6">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.festivals') }}</h1>
            <p class="crm-page-copy">{{ __('app.festivals_intro') }}</p>
        </div>

        @if ($canManage)
            @if ($tab === 'series')
                <x-ui.button :href="route('dashboard.accounts.festivals.series.create', $account)">
                    <x-ui.icon name="plus" class="h-4 w-4" /> {{ __('app.festival_series_create') }}
                </x-ui.button>
            @elseif (! $hasActiveSeries)
                <x-ui.button :href="route('dashboard.accounts.festivals.series.create', $account)">
                    <x-ui.icon name="plus" class="h-4 w-4" /> {{ __('app.festival_series_create') }}
                </x-ui.button>
            @endif
        @endif
    </header>

    @if ($canManage)
        <nav class="grid grid-cols-3 gap-1 rounded-xl bg-stone-100 p-1" aria-label="{{ __('app.festival_workspace_navigation') }}">
            <a href="{{ route('dashboard.accounts.festivals.index', ['account' => $account, 'tab' => 'festivals']) }}" class="crm-tab min-w-0 text-center leading-5" @if ($tab === 'festivals') aria-current="page" @endif>{{ __('app.festivals') }}</a>
            <a href="{{ route('dashboard.accounts.festivals.index', ['account' => $account, 'tab' => 'series']) }}" class="crm-tab min-w-0 text-center leading-5" @if ($tab === 'series') aria-current="page" @endif>{{ __('app.festival_series_tab') }}</a>
            <a href="{{ route('dashboard.accounts.festivals.index', ['account' => $account, 'tab' => 'payments']) }}" class="crm-tab min-w-0 text-center leading-5" @if ($tab === 'payments') aria-current="page" @endif>{{ __('app.festival_payments_tariffs_tab') }}</a>
        </nav>
    @endif

    @if ($canManage && in_array($tab, ['festivals', 'payments'], true) && ! $hasActiveSeries)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
            <strong>{{ __('app.festival_series_required') }}</strong>
        </div>
    @endif

    @if ($tab === 'festivals')
        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($editions as $edition)
                @php($coverUrl = $edition->coverMedia?->url())
                <article class="group overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm transition duration-200 hover:-translate-y-0.5 hover:shadow-xl">
                    <a href="{{ route('dashboard.accounts.festivals.show', [$account, $edition]) }}" class="relative block aspect-[16/9] overflow-hidden bg-[linear-gradient(135deg,#10233F_0%,#23405F_58%,#D9A441_145%)]">
                        @if ($coverUrl)
                            <img src="{{ $coverUrl }}" alt="{{ $edition->title }}" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.025]">
                            <span class="absolute inset-0 bg-gradient-to-t from-slate-950/65 via-slate-950/5 to-transparent"></span>
                        @else
                            <span class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.22),transparent_38%)]"></span>
                            <span class="flex h-full items-center justify-center"><x-ui.icon name="trophy" class="h-16 w-16 text-amber-200/90" /></span>
                        @endif
                        <span class="absolute left-4 top-4 rounded-full border border-white/20 bg-slate-950/55 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur">{{ __('app.festival_status_'.$edition->status->value) }}</span>
                        <span class="absolute bottom-4 left-4 right-4 truncate text-sm font-semibold text-white/90">{{ $edition->series->name }}</span>
                    </a>

                    <div class="p-5">
                        <h2 class="text-xl font-semibold text-slate-950"><a href="{{ route('dashboard.accounts.festivals.show', [$account, $edition]) }}" class="hover:text-brand-700">{{ $edition->title }}</a></h2>
                        <p class="mt-2 text-sm font-medium text-slate-600">{{ $edition->starts_at->timezone($edition->timezone)->format('d.m.Y H:i') }}</p>
                        @if ($edition->venue_name)
                            <p class="mt-1 truncate text-sm text-slate-500">{{ $edition->venue_name }}</p>
                        @endif

                        <div class="mt-5 grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-slate-50 p-3"><span class="block text-xs text-slate-500">{{ __('app.festival_entries') }}</span><strong class="mt-1 block">{{ $edition->entries_count }}</strong></div>
                            <div class="rounded-xl bg-slate-50 p-3"><span class="block text-xs text-slate-500">{{ __('app.festival_admission_types') }}</span><strong class="mt-1 block">{{ $edition->admission_types_count }}</strong></div>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <x-ui.button :href="route('dashboard.accounts.festivals.show', [$account, $edition])" variant="secondary">{{ __('app.open') }}</x-ui.button>
                            @if (in_array($edition->status->value, ['published', 'in_progress', 'completed'], true))
                                <x-ui.button :href="route('public.festivals.show', [$account->slug, $edition->slug])" variant="secondary" target="_blank">{{ __('app.festival_public_page') }}</x-ui.button>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="md:col-span-2 xl:col-span-3"><x-ui.empty-state icon="trophy">{{ __('app.festivals_empty') }}</x-ui.empty-state></div>
            @endforelse
        </div>

        {{ $editions->links() }}
    @elseif ($tab === 'series')
        <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
            <div class="divide-y divide-stone-100">
                @forelse ($series as $item)
                    <article class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-slate-950">{{ $item->name }}</h2>
                                <span class="{{ $item->is_active ? 'crm-status-active' : 'crm-status-muted' }}">{{ $item->is_active ? __('app.active') : __('app.inactive') }}</span>
                            </div>
                            @if ($item->summary)
                                <p class="mt-1 max-w-3xl text-sm text-slate-500">{{ $item->summary }}</p>
                            @endif
                            <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('app.festivals') }}: {{ $item->editions_count }}@if($item->organizer_name) · {{ $item->organizer_name }}@endif</p>
                        </div>
                        <x-ui.action-button :href="route('dashboard.accounts.festivals.series.edit', [$account, $item])" icon="edit" :label="__('app.edit')" />
                    </article>
                @empty
                    <div class="p-6"><x-ui.empty-state icon="trophy">{{ __('app.festival_series_empty') }}</x-ui.empty-state></div>
                @endforelse
            </div>
        </section>

        {{ $series->links() }}
    @else
        <section class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-5 shadow-xs">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="crm-page-kicker">{{ __('app.festival_prepaid_access') }}</div>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">{{ __('app.festival_entitlements') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_entitlements_help') }}</p>
                </div>
                @unless($isOwner)
                    <span class="crm-status-warning">{{ __('app.festival_owner_payment_required') }}</span>
                @endunless
            </div>

            @if ($isOwner && $festivalPackages->isNotEmpty())
                <div class="mt-5 grid gap-3 md:grid-cols-3">
                    @foreach($festivalPackages as $package)
                        <form method="POST" action="{{ route('dashboard.accounts.festivals.purchases.store', $account) }}" class="rounded-xl border border-white bg-white p-4 shadow-xs">
                            @csrf
                            <input type="hidden" name="festival_tariff_package_id" value="{{ $package->id }}">
                            <input type="hidden" name="idempotency_key" value="{{ (string) str()->uuid() }}">
                            <div class="flex items-start justify-between gap-3">
                                <strong class="text-2xl text-slate-950">{{ $package->name }}</strong>
                                <span class="font-semibold text-indigo-700">{{ \App\Support\MoneyFormatter::format($package->price_cents, $package->currency) }}</span>
                            </div>
                            <p class="mt-3 text-sm text-slate-600">{{ __('app.festival_package_limits', ['participants' => $package->max_participants, 'tickets' => $package->max_tickets]) }}</p>
                            <x-ui.button type="submit" class="mt-4 w-full">{{ $package->price_cents === 0 ? __('app.get_entitlement') : __('app.buy_festival') }}</x-ui.button>
                        </form>
                    @endforeach
                </div>
            @elseif($isOwner)
                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">{{ __('app.festival_purchase_unavailable') }}</p>
            @endif

            @if ($festivalPurchases->isNotEmpty())
                <div class="mt-5 divide-y divide-indigo-100 overflow-hidden rounded-xl border border-indigo-100 bg-white">
                    @foreach($festivalPurchases as $purchase)
                        <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <strong class="text-slate-950">{{ $purchase->tariff_name_snapshot }} · {{ $purchase->package_name_snapshot }}</strong>
                                <p class="mt-1 text-sm text-slate-500">{{ __('app.festival_package_limits', ['participants' => $purchase->max_participants, 'tickets' => $purchase->max_tickets]) }} · {{ \App\Support\MoneyFormatter::format($purchase->amount_cents, $purchase->currency) }}</p>
                                @if ($purchase->edition)
                                    <p class="mt-2 break-words text-sm text-slate-500">
                                        {{ __('app.festival_linked_edition') }}:
                                        <a href="{{ route('dashboard.accounts.festivals.show', [$account, $purchase->edition]) }}" class="font-semibold text-brand-700 hover:text-brand-800">{{ $purchase->edition->title }}</a>
                                    </p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="{{ in_array($purchase->status, [\App\Enums\FestivalEditionPurchaseStatus::Available, \App\Enums\FestivalEditionPurchaseStatus::Redeemed], true) ? 'crm-status-active' : (in_array($purchase->status, [\App\Enums\FestivalEditionPurchaseStatus::PaymentStarted, \App\Enums\FestivalEditionPurchaseStatus::PaymentPending], true) ? 'crm-status-scheduled' : 'crm-status-danger') }}">{{ __('app.festival_purchase_status_'.$purchase->status->value) }}</span>
                                @if ($purchase->status === \App\Enums\FestivalEditionPurchaseStatus::Available && $hasActiveSeries)
                                    <x-ui.button :href="route('dashboard.accounts.festivals.create', [$account, 'purchase' => $purchase->id])" size="sm">{{ __('app.continue_festival_creation') }}</x-ui.button>
                                @elseif ($isOwner && $purchase->status === \App\Enums\FestivalEditionPurchaseStatus::PaymentPending && $purchase->checkoutUrl())
                                    <x-ui.button :href="$purchase->checkoutUrl()" size="sm">{{ __('app.continue_payment') }}</x-ui.button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                @if($festivalPurchases->hasPages())<div class="mt-4">{{ $festivalPurchases->links() }}</div>@endif
            @endif
        </section>
    @endif
</div>
@endsection
