@extends('layouts.app')

@section('title', __('app.festival_fees').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header><p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p><h1 class="crm-page-title mt-2">{{ __('app.festival_fees') }}</h1><p class="crm-page-copy">{{ __('app.festival_fees_page_copy') }}</p></header>
    <x-festivals.settings-help :title="__('app.festival_fees_help_title')" :description="__('app.festival_fees_help_copy')" :dependencies="[__('app.festival_categories'), __('app.festival_registration_workflows'), __('app.festival_fees'), __('app.festival_entries'), __('app.payments')]" />
    <div class="space-y-4">
        @foreach($fees as $fee)
            @php($feeEditId = 'festival-fee-edit-'.$fee->id)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div><h2 class="text-lg font-semibold">{{ $fee->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('app.festival_charge_kind_'.$fee->kind) }} · {{ $fee->workflowStep?->title }} · {{ $fee->category?->name ?? __('app.all') }}</p><strong class="mt-2 block">{{ number_format($fee->amount_cents / 100, 2) }} {{ $fee->currency }}</strong></div>
                    <x-festivals.settings-actions :active="$fee->is_active" :toggle-route="route('dashboard.accounts.festivals.charge-definitions.toggle', [$account, $edition, $fee])" :move-route="route('dashboard.accounts.festivals.charge-definitions.move', [$account, $edition, $fee])" :edit-target="$feeEditId" />
                </div>
                <div id="{{ $feeEditId }}" class="mt-4 hidden gap-3 rounded-xl bg-stone-50 p-4">
                    <x-festivals.fee-form :$account :$edition :$fee />
                </div>
            </article>
        @endforeach
    </div>
    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm"><h2 class="text-lg font-semibold">{{ __('app.festival_add_fee') }}</h2><div class="mt-4"><x-festivals.fee-form :$account :$edition /></div></section>
</x-festivals.staff.workspace>
@endsection
