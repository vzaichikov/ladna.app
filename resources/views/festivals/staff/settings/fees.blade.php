@extends('layouts.app')

@section('title', __('app.festival_fees').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_fees')" :copy="__('app.festival_fees_page_copy')">
        <x-slot:actions><x-ui.button :href="route('dashboard.accounts.festivals.charge-definitions.create', [$account, $edition])"><x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_fee') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <x-festivals.settings-help :title="__('app.festival_fees_help_title')" :description="__('app.festival_fees_help_copy')" :dependencies="[__('app.festival_categories'), __('app.festival_registration_workflows'), __('app.festival_fees'), __('app.festival_entries'), __('app.payments')]" />

    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.settings.fees', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.settings.fees', [$account, $edition])" class="sm:grid-cols-2 xl:grid-cols-3">
        <label><span class="crm-label">{{ __('app.name') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
        <label><span class="crm-label">{{ __('app.type') }}</span><select name="kind" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($kinds as $kind)<option value="{{ $kind }}" @selected($filters['kind'] === $kind)>{{ __('app.festival_charge_kind_'.$kind) }}</option>@endforeach</select></label>
        <label><span class="crm-label">{{ __('app.festival_category') }}</span><select name="category" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->name }}</option>@endforeach</select></label>
        <label class="sm:col-span-2"><span class="crm-label">{{ __('app.festival_registration_workflow_step') }}</span><select name="workflow_step" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($workflows as $workflow)@foreach ($workflow->steps as $step)<option value="{{ $step->id }}" @selected($filters['workflow_step'] === $step->id)>{{ $workflow->name }} · {{ $step->title }}</option>@endforeach @endforeach</select></label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($fees as $fee)
            @php($globalIndex = ($fees->firstItem() ?? 1) + $loop->index)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_140px_auto] lg:items-center">
                <div><div class="flex flex-wrap items-center gap-2"><h2 class="font-semibold text-slate-950">{{ $fee->name }}</h2>@unless ($fee->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless</div><p class="mt-1 text-sm text-slate-500">{{ __('app.festival_charge_kind_'.$fee->kind) }}</p></div>
                <p class="text-sm text-slate-500">{{ $fee->workflowStep?->workflow?->name }} · {{ $fee->workflowStep?->title }} · {{ $fee->category?->name ?? __('app.all') }}</p>
                <strong class="text-sm text-slate-950">{{ \App\Support\MoneyFormatter::format($fee->amount_cents, $account->default_currency) }}</strong>
                <x-festivals.settings-actions :active="$fee->is_active" :toggle-route="route('dashboard.accounts.festivals.charge-definitions.toggle', [$account, $edition, $fee])" :move-route="route('dashboard.accounts.festivals.charge-definitions.move', [$account, $edition, $fee])" :edit-route="route('dashboard.accounts.festivals.charge-definitions.edit', [$account, $edition, $fee])" :show-ordering="! $hasFilters" :can-move-up="$globalIndex > 1" :can-move-down="$globalIndex < $fees->total()" />
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_fees_empty')" icon="badge-dollar-sign" class="m-5">{{ $hasFilters ? __('app.festival_filtered_empty_copy') : __('app.festival_fees_empty_copy') }}</x-ui.empty-state>
        @endforelse
    </x-ui.panel>
    <div>{{ $fees->links() }}</div>
</x-festivals.staff.workspace>
@endsection
