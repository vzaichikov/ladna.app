@extends('layouts.app')

@section('title', __('app.festival_readonly_summary').' - '.$entry->entry_name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$entry->entry_name" :copy="__('app.festival_readonly_summary_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry])">
                <x-ui.icon name="edit" class="h-4 w-4" />{{ __('app.festival_open_application') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.festivals.performances', [$account, $edition])" variant="secondary">{{ __('app.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-5 xl:grid-cols-2">
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-xs font-semibold text-slate-500">{{ $entry->code }}</span>
                <span class="crm-status-active">{{ __('app.festival_entry_status_accepted') }}</span>
            </div>
            <h2 class="mt-4 text-xl font-semibold text-slate-950">{{ __('app.festival_performance_details') }}</h2>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_category') }}</dt><dd class="mt-1 font-semibold text-slate-950">{{ $entry->category->name }}</dd><dd class="text-sm text-slate-500">{{ $entry->category->direction->name }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_applicant') }}</dt><dd class="mt-1 font-semibold text-slate-950">{{ $entry->portalUser->displayName() }}</dd><dd class="text-sm text-slate-500">{{ $entry->portalUser->email }} · {{ $entry->portalUser->phone }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_roster') }}</dt><dd class="mt-1 space-y-1">@foreach($entry->participants as $participant)<span class="block text-sm text-slate-700">{{ $participant->displayName() }}</span>@endforeach</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_act_title') }}</dt><dd class="mt-1 text-sm text-slate-700">{{ $entry->act_title ?: '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_act_description') }}</dt><dd class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $entry->act_description ?: '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_track_artist') }}</dt><dd class="mt-1 text-sm text-slate-700">{{ $entry->track_artist ?: '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_track_title') }}</dt><dd class="mt-1 text-sm text-slate-700">{{ $entry->track_title ?: '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_accepted_at') }}</dt><dd class="mt-1 text-sm text-slate-700">{{ $entry->accepted_at?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.festival_registration_completed_at') }}</dt><dd class="mt-1 text-sm text-slate-700">{{ $entry->registration_completed_at?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}</dd></div>
            </dl>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_registration_progress') }}</h2>
            <div class="mt-4 space-y-3">
                @forelse($entry->steps as $step)
                    <article class="rounded-xl bg-slate-50 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ $step->workflowStep->title }}</strong><span class="text-xs font-semibold text-slate-500">{{ __('app.festival_step_status_'.$step->status->value) }}</span></div>
                        @if($step->review_notes)<p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $step->review_notes }}</p>@endif
                    </article>
                @empty
                    <p class="text-sm text-slate-500">{{ __('app.festival_registration_complete') }}</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm xl:col-span-2">
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_checklist') }}</h2>
            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @forelse($entry->requirements as $requirement)
                    @php($submission = $requirement->submissions->first())
                    <article class="rounded-xl border border-stone-200 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ $requirement->definition->name }}</strong><span class="text-xs font-semibold text-slate-500">{{ __('app.festival_requirement_status_'.$requirement->status->value) }}</span></div>
                        @if($submission?->path)
                            <a href="{{ route('dashboard.accounts.festivals.submissions.download', [$account, $submission]) }}" class="mt-3 inline-flex text-sm font-semibold text-brand-700 hover:text-brand-800">{{ __('app.download') }} · {{ $submission->original_name }}</a>
                        @elseif($submission)
                            <x-festivals.response-value :definition="$requirement->definition" :value="$submission->value_json['value'] ?? null" class="mt-3 block rounded-lg bg-slate-50 p-3 text-sm text-slate-700" />
                        @else
                            <p class="mt-3 text-sm text-slate-500">—</p>
                        @endif
                        @if($requirement->review_notes)<p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ $requirement->review_notes }}</p>@endif
                    </article>
                @empty
                    <p class="text-sm text-slate-500">{{ __('app.festival_no_requirements') }}</p>
                @endforelse
            </div>
        </section>

        @if($workspacePermissions['finance'])
            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm xl:col-span-2">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_payments') }}</h2>
                <div class="mt-4 flex flex-col gap-3">
                    @forelse($entry->charges as $charge)
                        <article class="rounded-xl bg-slate-50 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3"><div><strong>{{ $charge->name }}</strong><span class="ml-2 text-xs text-slate-500">{{ __('app.festival_charge_status_'.$charge->status->value) }}</span></div><strong>{{ \App\Support\MoneyFormatter::format($charge->amount_cents, $charge->currency) }}</strong></div>
                        </article>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_no_payments') }}</p>
                    @endforelse
                </div>
            </section>
        @endif
    </div>
</x-festivals.staff.workspace>
@endsection
