@extends('layouts.app')

@section('title', __('app.festival_tab_applications').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_applications_title')" :copy="__('app.festival_applications_copy')" />

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
        @php
            $preservedApplicationFilters = collect($filters)
                ->except('queue')
                ->filter(fn ($value) => $value !== '')
                ->all();
        @endphp

        <div class="-mx-1 overflow-x-auto px-1 pb-2 pt-1">
            <nav class="flex min-w-max flex-nowrap gap-2 sm:min-w-0 sm:flex-wrap" aria-label="{{ __('app.festival_application_work_queues') }}">
                    <a
                        href="{{ route('dashboard.accounts.festivals.applications', array_merge([$account, $edition], $preservedApplicationFilters)) }}"
                        class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800 transition hover:border-slate-300 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 {{ $filters['queue'] === '' ? 'ring-2 ring-brand-500 ring-offset-2' : '' }}"
                        data-queue-pill="all"
                        @if($filters['queue'] === '') aria-current="page" @endif
                    >
                        <span>{{ __('app.all') }}</span>
                        <strong class="rounded-full bg-white/80 px-2 py-0.5 text-xs">{{ $queueCounts['all'] }}</strong>
                    </a>
                    @foreach ($queueKeys as $queue)
                    @php
                        $queueClasses = match ($queue) {
                            \App\Support\Festivals\FestivalApplicationIndex::QueueAwaitingReview => 'border-sky-200 bg-sky-50 text-sky-900 hover:border-sky-300 hover:bg-sky-100',
                            \App\Support\Festivals\FestivalApplicationIndex::QueueCorrectionsRequested => 'border-violet-200 bg-violet-50 text-violet-900 hover:border-violet-300 hover:bg-violet-100',
                            \App\Support\Festivals\FestivalApplicationIndex::QueuePaymentIncomplete => 'border-rose-200 bg-rose-50 text-rose-900 hover:border-rose-300 hover:bg-rose-100',
                            \App\Support\Festivals\FestivalApplicationIndex::QueueNotSubmitted => 'border-amber-200 bg-amber-50 text-amber-900 hover:border-amber-300 hover:bg-amber-100',
                            \App\Support\Festivals\FestivalApplicationIndex::QueueComplete => 'border-emerald-200 bg-emerald-50 text-emerald-900 hover:border-emerald-300 hover:bg-emerald-100',
                            \App\Support\Festivals\FestivalApplicationIndex::QueueClosed => 'border-slate-300 bg-slate-100 text-slate-800 hover:border-slate-400 hover:bg-slate-200',
                        };
                    @endphp
                    <a
                        href="{{ route('dashboard.accounts.festivals.applications', array_merge([$account, $edition], $preservedApplicationFilters, ['queue' => $queue])) }}"
                        class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 {{ $queueClasses }} {{ $filters['queue'] === $queue ? 'ring-2 ring-brand-500 ring-offset-2' : '' }}"
                        data-queue-pill="{{ $queue }}"
                        @if($filters['queue'] === $queue) aria-current="page" @endif
                    >
                        <span>{{ __('app.festival_application_queue_'.$queue) }}</span>
                        <strong class="rounded-full bg-white/80 px-2 py-0.5 text-xs">{{ $queueCounts[$queue] }}</strong>
                    </a>
                    @endforeach
            </nav>
        </div>

        <div class="mt-5">
            <x-ui.filter-bar
                :action="route('dashboard.accounts.festivals.applications', [$account, $edition])"
                :reset-href="route('dashboard.accounts.festivals.applications', [$account, $edition])"
                class="sm:grid-cols-2"
            >
                @if($filters['queue'] !== '')
                    <input type="hidden" name="queue" value="{{ $filters['queue'] }}">
                @endif
                <label class="block min-w-0">
                    <span class="crm-label">{{ __('app.search') }}</span>
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="crm-field min-h-11" placeholder="{{ __('app.festival_entry_search_placeholder') }}">
                </label>
                <label class="block min-w-0">
                    <span class="crm-label">{{ __('app.festival_category') }}</span>
                    <select name="category" class="crm-field min-h-11">
                        <option value="">{{ __('app.all') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid gap-3 pb-1 sm:col-span-2 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="block min-w-0">
                        <span class="crm-label">{{ __('app.festival_application_status_filter') }}</span>
                        <select name="status" class="crm-field min-h-11">
                            <option value="">{{ __('app.all') }}</option>
                            @foreach (\App\Enums\FestivalEntryStatus::cases() as $status)
                                <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ __('app.festival_entry_status_'.$status->value) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block min-w-0">
                        <span class="crm-label">{{ __('app.festival_current_step_filter') }}</span>
                        <select name="current_step" class="crm-field min-h-11">
                            <option value="">{{ __('app.all') }}</option>
                            @foreach ($currentStepGroups as $steps)
                                <optgroup label="{{ $steps->first()->workflow->name }}">
                                    @foreach ($steps as $step)
                                        <option value="{{ $step->id }}" @selected($filters['current_step'] === (string) $step->id)>
                                            {{ $step->title }}@if(! $step->is_active) · {{ __('app.inactive') }}@endif
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <label class="block min-w-0">
                        <span class="crm-label">{{ __('app.festival_checklist_filter') }}</span>
                        <select name="checklist" class="crm-field min-h-11">
                            <option value="">{{ __('app.all') }}</option>
                            <option value="open" @selected($filters['checklist'] === 'open')>{{ __('app.festival_checklist_open') }}</option>
                            <option value="complete" @selected($filters['checklist'] === 'complete')>{{ __('app.festival_checklist_complete') }}</option>
                        </select>
                    </label>
                    <label class="block min-w-0">
                        <span class="crm-label">{{ __('app.festival_current_step_payment_filter') }}</span>
                        <select name="payment" class="crm-field min-h-11">
                            <option value="">{{ __('app.all') }}</option>
                            <option value="incomplete" @selected($filters['payment'] === 'incomplete')>{{ __('app.festival_payment_filter_incomplete') }}</option>
                            <option value="paid" @selected($filters['payment'] === 'paid')>{{ __('app.festival_payment_filter_paid') }}</option>
                            <option value="not_required" @selected($filters['payment'] === 'not_required')>{{ __('app.festival_payment_filter_not_required') }}</option>
                        </select>
                    </label>
                </div>
            </x-ui.filter-bar>
        </div>

        <div class="mt-5 space-y-3">
            @forelse ($entries as $entry)
                @php
                    $qualificationReady = in_array($entry->qualification_status, [\App\Enums\FestivalQualificationStatus::NotRequired, \App\Enums\FestivalQualificationStatus::Passed], true);
                    $entryIsReady = $entry->status === \App\Enums\FestivalEntryStatus::Accepted
                        && $qualificationReady
                        && $entry->blocking_requirements_count === 0
                        && $entry->blocking_charges_count === 0
                        && $entry->scheduled_performance_slots_count > 0;
                    $currentStep = $entry->current_step_id
                        ? $entry->steps->firstWhere('id', (int) $entry->current_step_id)
                        : null;
                    $currentPaymentCharges = $currentStep?->charges
                        ->filter(fn ($charge) => $charge->amount_cents > 0 && $charge->status !== \App\Enums\FestivalChargeStatus::Cancelled)
                        ?? collect();
                    $currentStepIsPaid = $currentPaymentCharges->isNotEmpty()
                        && $currentPaymentCharges->every(fn ($charge) => $charge->status === \App\Enums\FestivalChargeStatus::Paid);
                @endphp
                <article class="rounded-xl border border-stone-200 bg-slate-50/70 p-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-semibold text-slate-500">{{ $entry->code }}</span>
                                <span class="{{ $entry->status->badgeClass() }}">{{ __('app.festival_entry_status_'.$entry->status->value) }}</span>
                                @if($currentPaymentCharges->isNotEmpty())
                                    <span class="{{ $currentStepIsPaid ? 'crm-status-active' : 'crm-status-danger' }}">
                                        {{ $currentStepIsPaid ? __('app.festival_charge_status_paid') : __('app.festival_application_payment_unpaid') }}
                                    </span>
                                @endif
                                <span class="{{ $entryIsReady ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }} rounded-full px-2.5 py-1 text-xs font-semibold">
                                    {{ $entryIsReady ? __('app.festival_ready') : __('app.festival_not_ready') }}
                                </span>
                            </div>
                            <h3 class="mt-2 truncate text-lg font-semibold text-slate-950">{{ $entry->entry_name }}</h3>
                            <p class="text-sm text-slate-500">{{ $entry->category->direction->name }} · {{ $entry->category->name }}</p>
                            <p class="mt-1 text-sm text-slate-600">
                                <span class="font-semibold text-slate-700">{{ __('app.festival_current_step') }}:</span>
                                @if($currentStep)
                                    {{ $currentStep->workflowStep->title }} · {{ __('app.festival_step_status_'.$currentStep->status->value) }}
                                @elseif($entry->steps->isNotEmpty())
                                    {{ __('app.festival_registration_complete') }}
                                @else
                                    —
                                @endif
                            </p>
                            @if ($workspacePermissions['registrations'])
                                <p class="mt-1 text-sm text-slate-600">{{ $entry->portalUser->displayName() }} · {{ $entry->portalUser->email }}</p>
                            @endif
                        </div>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="grid grid-cols-3 gap-2 text-center text-xs sm:min-w-72">
                                <div class="rounded-lg bg-white px-3 py-2"><strong class="block text-base">{{ $entry->current_checklist_open_count }}</strong>{{ __('app.festival_requirements_open') }}</div>
                                <div class="rounded-lg bg-white px-3 py-2"><strong class="block text-base">{{ $entry->blocking_charges_count }}</strong>{{ __('app.festival_charges_open') }}</div>
                                <div class="rounded-lg bg-white px-3 py-2"><strong class="block text-base">{{ $entry->performance_slots_count }}</strong>{{ __('app.festival_program_slots') }}</div>
                            </div>
                            <x-ui.button :href="route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry])">
                                <x-ui.icon name="edit" class="h-4 w-4" />{{ __('app.festival_open_application') }}
                            </x-ui.button>
                        </div>
                    </div>
                </article>
            @empty
                <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_applications_empty')" icon="accounts">
                    @if ($hasFilters)
                        <x-ui.button :href="route('dashboard.accounts.festivals.applications', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                    @endif
                </x-ui.empty-state>
            @endforelse
        </div>

        <div class="mt-5">{{ $entries->links() }}</div>
    </section>
</x-festivals.staff.workspace>
@endsection
