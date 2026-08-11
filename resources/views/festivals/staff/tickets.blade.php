@extends('layouts.app')

@section('title', __('app.festival_tab_tickets_entrance').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_tickets_title')" :copy="__('app.festival_tickets_copy')">
        <x-slot:actions>
            @if ($workspacePermissions['ticket_check_in'])
                <x-ui.button :href="route('dashboard.accounts.festivals.scanner', [$account, $edition])"><x-ui.icon name="qr-code" class="h-4 w-4" /> {{ __('app.festival_open_scanner') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @if ($workspacePermissions['finance'])
            <div class="rounded-2xl bg-white p-5 shadow-crm"><span class="text-sm text-slate-500">{{ __('app.festival_paid_orders') }}</span><strong class="mt-1 block text-2xl">{{ $admissionReport['paid_orders'] }}</strong></div>
            <div class="rounded-2xl bg-white p-5 shadow-crm"><span class="text-sm text-slate-500">{{ __('app.festival_admission_revenue') }}</span><strong class="mt-1 block text-2xl">{{ number_format($admissionReport['revenue_cents'] / 100, 2) }} {{ $edition->currency }}</strong></div>
        @endif
        <div class="rounded-2xl bg-white p-5 shadow-crm"><span class="text-sm text-slate-500">{{ __('app.festival_tickets_issued') }}</span><strong class="mt-1 block text-2xl">{{ $admissionReport['tickets'] }}</strong></div>
        <div class="rounded-2xl bg-white p-5 shadow-crm"><span class="text-sm text-slate-500">{{ __('app.festival_tickets_checked_in') }}</span><strong class="mt-1 block text-2xl">{{ $admissionReport['checked_in'] }}</strong></div>
    </div>

    @if ($workspacePermissions['finance'])
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xl font-semibold">{{ __('app.festival_admission_types') }}</h2>
                    <span class="text-sm text-slate-500">{{ $admissionTypes->count() }}</span>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @forelse ($admissionTypes as $admissionType)
                        @php($availability = $admissionAvailability[$admissionType->id])
                        <article class="rounded-xl border border-stone-200 bg-slate-50 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div><strong>{{ $admissionType->name }}</strong><p class="mt-1 text-sm text-slate-500">{{ $admissionType->description }}</p></div>
                                <span class="{{ $admissionType->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-700' }} rounded-full px-2.5 py-1 text-xs font-semibold">{{ $admissionType->is_active ? __('app.active') : __('app.inactive') }}</span>
                            </div>
                            <dl class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                                <div class="rounded-lg bg-white p-2"><dt class="text-slate-500">{{ __('app.festival_inventory') }}</dt><dd class="mt-1 font-semibold">{{ $admissionType->inventory }}</dd></div>
                                <div class="rounded-lg bg-white p-2"><dt class="text-slate-500">{{ __('app.festival_remaining') }}</dt><dd class="mt-1 font-semibold">{{ $availability['remaining'] }}</dd></div>
                                <div class="rounded-lg bg-white p-2"><dt class="text-slate-500">{{ __('app.price') }}</dt><dd class="mt-1 font-semibold">{{ number_format($availability['current_price_cents'] / 100, 2) }}</dd></div>
                            </dl>
                            <p class="mt-3 text-xs text-slate-500">{{ __('app.festival_price_tier_'.$availability['price_tier']) }} · {{ $edition->currency }}</p>
                        </article>
                    @empty
                        <div class="md:col-span-2"><x-ui.empty-state icon="ticket">{{ __('app.festival_admission_types_empty') }}</x-ui.empty-state></div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <h2 class="text-lg font-semibold">{{ __('app.festival_add_admission_type') }}</h2>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]) }}" class="mt-4 space-y-3">
                    @csrf
                    <input name="name" required placeholder="{{ __('app.festival_admission_name') }}" class="crm-field">
                    <textarea name="description" rows="2" placeholder="{{ __('app.description') }}" class="crm-field"></textarea>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="inventory" min="1" required placeholder="{{ __('app.festival_inventory') }}" class="crm-field">
                        <input type="number" name="price_cents" min="0" required placeholder="{{ __('app.festival_regular_price') }}" class="crm-field">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="early_bird_price_cents" min="0" placeholder="{{ __('app.festival_early_price') }}" class="crm-field">
                        <input type="number" name="early_bird_quota" min="1" placeholder="{{ __('app.festival_early_quota') }}" class="crm-field">
                    </div>
                    <label><span class="crm-label">{{ __('app.festival_early_ends') }}</span><input type="datetime-local" name="early_bird_ends_at" class="crm-field"></label>
                    <div class="grid grid-cols-2 gap-2">
                        <label><span class="crm-label">{{ __('app.festival_sales_start') }}</span><input type="datetime-local" name="sales_starts_at" class="crm-field"></label>
                        <label><span class="crm-label">{{ __('app.festival_sales_end') }}</span><input type="datetime-local" name="sales_ends_at" class="crm-field"></label>
                    </div>
                    <label><span class="crm-label">{{ __('app.festival_max_per_order') }}</span><input type="number" name="max_per_order" min="1" max="20" value="10" required class="crm-field"></label>
                    <x-ui.button type="submit" class="w-full">{{ __('app.add') }}</x-ui.button>
                </form>
            </section>
        </div>

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <div class="flex items-center justify-between gap-4"><h2 class="text-xl font-semibold">{{ __('app.festival_orders') }}</h2><span class="text-sm text-slate-500">{{ $orders->total() }}</span></div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">{{ __('app.order') }}</th><th class="px-3 py-2">{{ __('app.buyer') }}</th><th class="px-3 py-2">{{ __('app.status') }}</th><th class="px-3 py-2">{{ __('app.amount') }}</th><th class="px-3 py-2">{{ __('app.festival_tickets') }}</th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($orders as $order)
                            <tr><td class="px-3 py-3 font-mono text-xs">{{ $order->order_id }}</td><td class="px-3 py-3"><strong>{{ $order->buyer_name }}</strong><span class="block text-xs text-slate-500">{{ $order->buyer_email }}</span></td><td class="px-3 py-3">{{ __('app.festival_order_'.$order->status->value) }}</td><td class="px-3 py-3 font-semibold">{{ number_format($order->amount_cents / 100, 2) }} {{ $order->currency }}</td><td class="px-3 py-3">{{ $order->tickets_count }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">{{ __('app.festival_orders_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-5">{{ $orders->links() }}</div>
        </section>
    @endif
</x-festivals.staff.workspace>
@endsection
