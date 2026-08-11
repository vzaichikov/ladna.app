@extends('layouts.app')

@section('title', __('app.festival_tab_applications').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <div>
        <p class="crm-page-kicker">{{ __('app.festival_tab_applications') }}</p>
        <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('app.festival_applications_title') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_applications_copy') }}</p>
    </div>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold">{{ __('app.festival_entries') }}</h2>
            <span class="text-sm font-semibold text-slate-500">{{ $entries->total() }}</span>
        </div>

        <div class="mt-4 space-y-3">
            @forelse ($entries as $entry)
                @php
                    $qualificationReady = in_array($entry->qualification_status, [\App\Enums\FestivalQualificationStatus::NotRequired, \App\Enums\FestivalQualificationStatus::Passed], true);
                    $entryIsReady = $entry->status === \App\Enums\FestivalEntryStatus::Accepted
                        && $qualificationReady
                        && $entry->blocking_requirements_count === 0
                        && $entry->blocking_charges_count === 0
                        && $entry->performance_slots_count > 0;
                    $currentStep = $workspacePermissions['registrations']
                        ? $entry->steps->first(fn($step) => $step->status !== \App\Enums\FestivalEntryStepStatus::Approved)
                        : null;
                    $category = $entry->category;
                    $categoryName = $entry->category->name;
                    $directionName = $entry->category->direction->name;
                @endphp
                <details class="rounded-xl border border-stone-200 bg-slate-50/70 p-4">
                    <summary class="cursor-pointer list-none">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs font-semibold text-slate-500">{{ $entry->code }}</span>
                                    <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_entry_status_'.$entry->status->value) }}</span>
                                    <span class="{{ $entryIsReady ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }} rounded-full px-2.5 py-1 text-xs font-semibold">
                                        {{ $entryIsReady ? __('app.festival_ready') : __('app.festival_not_ready') }}
                                    </span>
                                </div>
                                <strong class="mt-2 block truncate text-lg text-slate-950">{{ $entry->entry_name }}</strong>
                                <span class="text-sm text-slate-500">{{ $directionName }} · {{ $categoryName }}</span>
                                @if ($workspacePermissions['registrations'])
                                    <span class="text-sm text-slate-500"> · {{ $entry->portalUser->email }}</span>
                                @endif
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-center text-xs sm:min-w-72">
                                <div class="rounded-lg bg-white px-3 py-2"><strong class="block text-base">{{ $entry->blocking_requirements_count }}</strong>{{ __('app.festival_requirements_open') }}</div>
                                <div class="rounded-lg bg-white px-3 py-2"><strong class="block text-base">{{ $entry->blocking_charges_count }}</strong>{{ __('app.festival_charges_open') }}</div>
                                <div class="rounded-lg bg-white px-3 py-2"><strong class="block text-base">{{ $entry->performance_slots_count }}</strong>{{ __('app.festival_program_slots') }}</div>
                            </div>
                        </div>
                    </summary>

                    <div class="mt-5 grid gap-5 border-t border-stone-200 pt-5 xl:grid-cols-2">
                        <section class="rounded-xl border border-stone-200 bg-white p-4 xl:col-span-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ $directionName }}</p>
                            <h3 class="mt-1 font-semibold text-slate-950">{{ $categoryName }}</h3>
                            <dl class="mt-3 flex flex-wrap gap-2 text-xs text-slate-700">
                                <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_roster') }}</dt><dd>{{ __('app.festival_participants_range', ['min' => $category->min_members, 'max' => $category->max_members]) }}</dd></div>
                                @if($category->min_age !== null || $category->max_age !== null)
                                    <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_age_limits') }}</dt><dd>{{ __('app.festival_age_range', ['min' => $category->min_age ?? '—', 'max' => $category->max_age ?? '—']) }}</dd></div>
                                @endif
                                @if($category->min_duration_seconds !== null || $category->max_duration_seconds !== null)
                                    <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_performance_duration') }}</dt><dd>{{ __('app.festival_duration_range', ['min' => $category->min_duration_seconds ?? '—', 'max' => $category->max_duration_seconds ?? '—']) }}</dd></div>
                                @endif
                                @if($category->registration_closes_at)
                                    <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_registration_closes_at') }}</dt><dd>{{ __('app.festival_category_deadline_value', ['date' => $category->registration_closes_at->timezone($edition->timezone)->format('d.m.Y H:i'), 'timezone' => $edition->timezone]) }}</dd></div>
                                @endif
                            </dl>
                            <div class="mt-4 border-t border-stone-200 pt-4">
                                <h4 class="text-sm font-semibold text-slate-950">{{ __('app.festival_category_requirements') }}</h4>
                                @if($category->requirements_html)
                                    <div class="prose prose-slate mt-2 max-w-none text-sm">{!! $category->requirements_html !!}</div>
                                @else
                                    <p class="mt-2 text-sm text-slate-500">{{ __('app.festival_category_requirements_none') }}</p>
                                @endif
                            </div>
                        </section>

                        @if ($workspacePermissions['registrations'])
                            <section>
                                <h3 class="font-semibold text-slate-950">{{ __('app.festival_application_review') }}</h3>
                                @if($currentStep)
                                    <div class="mt-3 rounded-xl border border-stone-200 bg-white p-4"><p class="text-xs font-semibold text-brand-700">{{ __('app.festival_current_step') }}</p><strong class="mt-1 block">{{ $currentStep->title }}</strong><span class="mt-1 block text-xs text-slate-500">{{ __('app.festival_step_status_'.$currentStep->status->value) }}</span>@if($currentStep->review_notes)<p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $currentStep->review_notes }}</p>@endif</div>
                                    @if($currentStep->status === \App\Enums\FestivalEntryStepStatus::Submitted)
                                        <form method="POST" action="{{ route('dashboard.accounts.festivals.entry-steps.review', [$account, $edition, $entry, $currentStep]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">@csrf @method('PATCH')<label><span class="crm-label">{{ __('app.status') }}</span><select name="decision" class="crm-field"><option value="approve">{{ __('app.festival_review_approve') }}</option><option value="request_changes">{{ __('app.festival_review_request_changes') }}</option><option value="reject_entry">{{ __('app.festival_review_reject_entry') }}</option></select></label><label><span class="crm-label">{{ __('app.festival_revision_due_at') }}</span><input type="datetime-local" name="revision_due_at" class="crm-field"></label><label class="sm:col-span-2"><span class="crm-label">{{ __('app.festival_review_comment') }}</span><textarea name="comment" rows="3" class="crm-field"></textarea></label><div class="sm:col-span-2"><x-ui.button type="submit">{{ __('app.save') }}</x-ui.button></div></form>
                                    @endif
                                @else
                                    <p class="mt-3 rounded-xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{{ __('app.festival_registration_complete') }}</p>
                                @endif
                            </section>

                            <section>
                                <h3 class="font-semibold text-slate-950">{{ __('app.festival_checklist') }}</h3>
                                <div class="mt-3 space-y-3">
                                    @forelse ($entry->requirements as $requirement)
                                        @php($latestSubmission = $requirement->submissions->first())
                                        <div class="rounded-lg border border-stone-200 bg-white p-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <strong class="text-sm">{{ $requirement->definition_snapshot['name'] ?? $requirement->definition?->name }}</strong>
                                                    <span class="ml-2 text-xs text-slate-500">{{ __('app.festival_requirement_status_'.$requirement->status->value) }}</span>
                                                </div>
                                                @if ($latestSubmission?->path)
                                                    <a href="{{ route('dashboard.accounts.festivals.submissions.download', [$account, $latestSubmission]) }}" class="text-xs font-semibold text-brand-700 hover:text-brand-800">{{ __('app.download') }} · {{ $latestSubmission->original_name }}</a>
                                                @endif
                                            </div>
                                            @if ($latestSubmission && ! $latestSubmission->path)
                                                <x-festivals.response-value :snapshot="$requirement->definition_snapshot" :value="$latestSubmission->value_json['value'] ?? null" class="mt-3 block rounded-lg bg-slate-50 p-3 text-sm text-slate-700" />
                                            @endif
                                            <form method="POST" action="{{ route('dashboard.accounts.festivals.requirements.review', [$account, $edition, $requirement]) }}" class="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                                                @csrf
                                                @method('PATCH')
                                                <select name="status" class="crm-field mt-0">
                                                    @foreach ([\App\Enums\FestivalRequirementStatus::Accepted, \App\Enums\FestivalRequirementStatus::Rejected, \App\Enums\FestivalRequirementStatus::Waived] as $status)
                                                        <option value="{{ $status->value }}" @selected($requirement->status === $status)>{{ __('app.festival_requirement_status_'.$status->value) }}</option>
                                                    @endforeach
                                                </select>
                                                <input name="review_notes" value="{{ $requirement->review_notes }}" placeholder="{{ __('app.notes') }}" class="crm-field mt-0">
                                                <x-ui.button type="submit" size="sm">{{ __('app.save') }}</x-ui.button>
                                            </form>
                                        </div>
                                    @empty
                                        <p class="text-sm text-slate-500">{{ __('app.festival_no_requirements') }}</p>
                                    @endforelse
                                </div>
                            </section>
                        @endif

                        @if ($workspacePermissions['finance'])
                            <section class="xl:col-span-2">
                                <h3 class="font-semibold text-slate-950">{{ __('app.festival_payments') }}</h3>
                                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                    @forelse ($entry->charges as $charge)
                                        <div class="rounded-lg border border-stone-200 bg-white p-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <strong>{{ $charge->name }}</strong>
                                                    <span class="ml-2 text-xs text-slate-500">{{ __('app.festival_charge_status_'.$charge->status->value) }}</span>
                                                </div>
                                                <strong>{{ number_format($charge->amount_cents / 100, 2) }} {{ $charge->currency }}</strong>
                                            </div>
                                            @if ($attempt = $charge->paymentAttempts->sortByDesc('id')->first())
                                                <p class="mt-2 text-xs text-slate-500">{{ $attempt->provider }} · {{ __('app.festival_payment_status_'.$attempt->status->value) }} · {{ $attempt->order_id }}</p>
                                            @endif
                                            <form method="POST" action="{{ route('dashboard.accounts.festivals.charges.manual-review', [$account, $edition, $charge]) }}" class="mt-3 grid gap-2 sm:grid-cols-[auto_minmax(0,1fr)_auto]">
                                                @csrf
                                                @method('PATCH')
                                                <select name="decision" class="crm-field mt-0">
                                                    <option value="approve">{{ __('app.accept') }}</option>
                                                    <option value="reject">{{ __('app.reject') }}</option>
                                                </select>
                                                <input name="notes" value="{{ $charge->notes }}" placeholder="{{ __('app.notes') }}" class="crm-field mt-0">
                                                <x-ui.button type="submit" size="sm">{{ __('app.save') }}</x-ui.button>
                                            </form>
                                        </div>
                                    @empty
                                        <p class="text-sm text-slate-500">{{ __('app.festival_no_payments') }}</p>
                                    @endforelse
                                </div>
                            </section>
                        @endif
                    </div>
                </details>
            @empty
                <x-ui.empty-state icon="trophy">{{ __('app.festival_entries_empty') }}</x-ui.empty-state>
            @endforelse
        </div>

        <div class="mt-5">{{ $entries->links() }}</div>
    </section>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
        <h2 class="text-xl font-semibold">{{ __('app.festival_application_statistics') }}</h2>
        <div class="mt-4 grid gap-5 lg:grid-cols-3">
            <div>
                <h3 class="font-semibold">{{ __('app.festival_entries_by_status') }}</h3>
                <dl class="mt-2 space-y-2">
                    @foreach ($entryStatistics as $label => $count)
                        <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2"><dt>{{ __('app.festival_entry_status_'.$label) }}</dt><dd class="font-semibold">{{ $count }}</dd></div>
                    @endforeach
                </dl>
            </div>
            <div>
                <h3 class="font-semibold">{{ __('app.festival_entries_by_category') }}</h3>
                <dl class="mt-2 space-y-2">
                    @foreach ($categoryStatistics as $row)
                        <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2"><dt>{{ $row['label'] }}</dt><dd class="font-semibold">{{ $row['count'] }}</dd></div>
                    @endforeach
                </dl>
            </div>
            <div>
                <h3 class="font-semibold">{{ __('app.festival_entries_by_direction') }}</h3>
                <dl class="mt-2 space-y-2">
                    @foreach ($directionStatistics as $row)
                        <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2"><dt>{{ $row['label'] }}</dt><dd class="font-semibold">{{ $row['count'] }}</dd></div>
                    @endforeach
                </dl>
            </div>
        </div>
    </section>
</x-festivals.staff.workspace>
@endsection
