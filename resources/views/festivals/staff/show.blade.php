@extends('layouts.app')

@section('title', $edition->title.' - '.$account->name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="crm-page-kicker">{{ $edition->series->name }}</p>
            <h1 class="crm-page-title mt-2">{{ $edition->title }}</h1>
            <p class="crm-page-copy">
                {{ $edition->starts_at->timezone($edition->timezone)->format('d.m.Y H:i') }}
                @if ($edition->venue_name) · {{ $edition->venue_name }} @endif
            </p>
            <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-slate-700">{{ __('app.festival_status_'.$edition->status->value) }}</span>
                <span class="rounded-full bg-brand-50 px-3 py-1.5 text-brand-700">{{ __('app.festival_registration_'.$edition->registration_status->value) }}</span>
                <span class="rounded-full bg-stone-100 px-3 py-1.5 text-stone-700">{{ $edition->timezone }} · {{ $edition->currency }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if (in_array($edition->status->value, ['published', 'in_progress', 'completed'], true))
                <x-ui.button :href="route('public.festivals.show', [$account->slug, $edition->slug])" variant="secondary">{{ __('app.festival_public_page') }}</x-ui.button>
            @endif
            @if ($workspacePermissions['manage'])
                <x-ui.button :href="route('dashboard.accounts.festivals.edit', [$account, $edition])" variant="secondary">{{ __('app.edit') }}</x-ui.button>
            @endif
        </div>
    </header>
    <section>
        <div>
            <h2 class="text-2xl font-semibold text-slate-950">{{ __('app.festival_overview_title') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_overview_copy') }}</p>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl bg-white p-5 shadow-crm">
                <span class="text-sm text-slate-500">{{ __('app.festival_entries') }}</span>
                <strong class="mt-1 block text-2xl">{{ $edition->entries_count }}</strong>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow-crm">
                <span class="text-sm text-slate-500">{{ __('app.festival_program_slots') }}</span>
                <strong class="mt-1 block text-2xl">{{ $edition->schedule_slots_count }}</strong>
            </div>
            @if ($workspacePermissions['finance'] || $workspacePermissions['ticket_check_in'])
                <div class="rounded-2xl bg-white p-5 shadow-crm">
                    <span class="text-sm text-slate-500">{{ __('app.festival_tickets_issued') }}</span>
                    <strong class="mt-1 block text-2xl">{{ $edition->tickets_count }}</strong>
                </div>
            @endif
            <div class="rounded-2xl bg-white p-5 shadow-crm">
                <span class="text-sm text-slate-500">{{ __('app.festival_registration_closes') }}</span>
                <strong class="mt-1 block text-lg">
                    {{ $edition->registration_closes_at?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}
                </strong>
            </div>
        </div>
    </section>

    @if ($entriesAwaitingReview !== null || $requirementsAwaitingReview !== null || $chargesAwaitingPayment !== null || $ticketsCheckedIn !== null)
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <h2 class="text-xl font-semibold">{{ __('app.festival_action_required') }}</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @if ($entriesAwaitingReview !== null)
                    <a href="{{ route('dashboard.accounts.festivals.applications', [$account, $edition]) }}" class="rounded-xl bg-amber-50 p-4 transition hover:bg-amber-100">
                        <span class="text-sm text-amber-800">{{ __('app.festival_entries_awaiting_review') }}</span>
                        <strong class="mt-1 block text-2xl text-amber-950">{{ $entriesAwaitingReview }}</strong>
                    </a>
                @endif
                @if ($requirementsAwaitingReview !== null)
                    <a href="{{ route('dashboard.accounts.festivals.applications', [$account, $edition]) }}" class="rounded-xl bg-sky-50 p-4 transition hover:bg-sky-100">
                        <span class="text-sm text-sky-800">{{ __('app.festival_requirements_awaiting_review') }}</span>
                        <strong class="mt-1 block text-2xl text-sky-950">{{ $requirementsAwaitingReview }}</strong>
                    </a>
                @endif
                @if ($chargesAwaitingPayment !== null)
                    <a href="{{ route('dashboard.accounts.festivals.applications', [$account, $edition]) }}" class="rounded-xl bg-rose-50 p-4 transition hover:bg-rose-100">
                        <span class="text-sm text-rose-800">{{ __('app.festival_charges_awaiting_payment') }}</span>
                        <strong class="mt-1 block text-2xl text-rose-950">{{ $chargesAwaitingPayment }}</strong>
                    </a>
                @endif
                @if ($ticketsCheckedIn !== null)
                    <a href="{{ route('dashboard.accounts.festivals.tickets', [$account, $edition]) }}" class="rounded-xl bg-emerald-50 p-4 transition hover:bg-emerald-100">
                        <span class="text-sm text-emerald-800">{{ __('app.festival_tickets_checked_in') }}</span>
                        <strong class="mt-1 block text-2xl text-emerald-950">{{ $ticketsCheckedIn }}</strong>
                    </a>
                @endif
            </div>
        </section>
    @endif

    @if ($upcomingSlots->isNotEmpty())
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-xl font-semibold">{{ __('app.festival_upcoming_program') }}</h2>
                @if ($workspacePermissions['schedule'])
                    <a href="{{ route('dashboard.accounts.festivals.program', [$account, $edition]) }}" class="text-sm font-semibold text-brand-700 hover:text-brand-800">{{ __('app.view_all') }}</a>
                @endif
            </div>
            <div class="mt-4 divide-y divide-stone-100">
                @foreach ($upcomingSlots as $slot)
                    <div class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <strong>{{ $slot->entry->entry_name }}</strong>
                            <span class="ml-2 text-sm text-slate-500">{{ $slot->stage->name }} · {{ $slot->type === \App\Enums\FestivalScheduleSlotType::Rehearsal ? __('app.festival_rehearsal') : __('app.performance') }}</span>
                        </div>
                        <time class="text-sm font-semibold text-slate-700">{{ $slot->starts_at->timezone($edition->timezone)->format('d.m H:i') }}</time>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</x-festivals.staff.workspace>
@endsection
