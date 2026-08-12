@extends('layouts.public', ['hideAppFooter' => true])

@section('title', $entry->entry_name.' - '.$entry->edition->title)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')
        @php
            $categoryName = $entry->category->name;
            $directionName = $entry->category->direction->name;
            $categoryRequirementsHtml = $entry->category->requirements_html;
        @endphp
        <header class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="font-mono text-sm text-slate-500">{{ $entry->code }}</p>
                <h1 class="mt-1 text-3xl font-semibold sm:text-4xl">{{ $entry->entry_name }}</h1>
                <p class="mt-2 text-slate-600">{{ $entry->edition->title }} · {{ $directionName }} · {{ $categoryName }}</p>
            </div>
            @if ($entry->registration_completed_at)
                <span class="rounded-full bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-800">{{ __('app.festival_registration_complete') }}</span>
            @endif
        </header>

        @if (session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="mt-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $errors->first() }}</div>@endif

        <section class="mt-7 rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ $directionName }}</p>
            <h2 class="mt-1 text-xl font-semibold text-slate-950">{{ $categoryName }}</h2>
            <dl class="mt-3 flex flex-wrap gap-2 text-xs text-slate-700">
                <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_roster') }}</dt><dd>{{ __('app.festival_participants_range', ['min' => $entry->category->min_members, 'max' => $entry->category->max_members]) }}</dd></div>
                @if($entry->category->min_age !== null || $entry->category->max_age !== null)
                    <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_age_limits') }}</dt><dd>{{ __('app.festival_age_range', ['min' => $entry->category->min_age ?? '—', 'max' => $entry->category->max_age ?? '—']) }}</dd></div>
                @endif
                @if($entry->category->min_duration_seconds !== null || $entry->category->max_duration_seconds !== null)
                    <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_performance_duration') }}</dt><dd>{{ __('app.festival_duration_range', ['min' => $entry->category->min_duration_seconds ?? '—', 'max' => $entry->category->max_duration_seconds ?? '—']) }}</dd></div>
                @endif
                @if($entry->category->registration_closes_at)
                    <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_registration_closes_at') }}</dt><dd>{{ __('app.festival_category_deadline_value', ['date' => $entry->category->registration_closes_at->timezone($entry->edition->timezone)->format('d.m.Y H:i'), 'timezone' => $entry->edition->timezone]) }}</dd></div>
                @endif
            </dl>
            <div class="mt-4 border-t border-stone-200 pt-4">
                <h3 class="text-sm font-semibold text-slate-950">{{ __('app.festival_category_requirements') }}</h3>
                @if($categoryRequirementsHtml)
                    <div class="prose prose-slate mt-2 max-w-none text-sm">{!! $categoryRequirementsHtml !!}</div>
                @else
                    <p class="mt-2 text-sm text-slate-500">{{ __('app.festival_category_requirements_none') }}</p>
                @endif
            </div>
        </section>

        <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-4 shadow-crm sm:p-6">
            <div class="flex items-center justify-between gap-3"><h2 class="text-xl font-semibold">{{ __('app.festival_registration_progress') }}</h2><span class="text-sm text-slate-500">{{ $entry->steps->where('status', App\Enums\FestivalEntryStepStatus::Approved)->count() }}/{{ $entry->steps->count() }}</span></div>
            <ol class="mt-5 grid gap-3 md:grid-cols-4">
                @foreach($workflowStates as $index => $state)
                    @php $step = $state['step']; @endphp
                    <li>
                        <a href="{{ route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]) }}" class="block h-full rounded-xl border p-4 transition {{ $selectedStep?->is($step) ? 'border-brand-500 bg-brand-50' : 'border-stone-200 hover:border-brand-300' }} {{ !$state['available'] ? 'opacity-60' : '' }}">
                            <div class="flex items-center gap-2"><span class="flex size-7 shrink-0 items-center justify-center rounded-full {{ $step->status === \App\Enums\FestivalEntryStepStatus::Approved ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-700' }} text-xs font-bold">{{ $index + 1 }}</span><strong class="text-sm">{{ $step->workflowStep->title }}</strong></div>
                            <span class="mt-2 block text-xs text-slate-500">{{ __('app.festival_step_status_'.$step->status->value) }}</span>
                            @if($state['locked_reason'])<span class="mt-2 block text-xs text-amber-800">{{ $state['locked_reason'] }}</span>@endif
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>

        @if($selectedStep)
            @php $selectedState = $workflowStates->first(fn($state) => $state['step']->is($selectedStep)); @endphp
            <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div><p class="text-sm font-semibold text-brand-700">{{ __('app.festival_current_step') }}</p><h2 class="mt-1 text-2xl font-semibold">{{ $selectedStep->workflowStep->title }}</h2>@if($selectedStep->workflowStep->description)<p class="mt-2 max-w-3xl text-sm text-slate-600">{{ $selectedStep->workflowStep->description }}</p>@endif</div>
                    <span class="self-start rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ __('app.festival_step_status_'.$selectedStep->status->value) }}</span>
                </div>

                @if($selectedStep->status === \App\Enums\FestivalEntryStepStatus::ChangesRequested)
                    <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"><strong>{{ __('app.festival_review_comment') }}</strong><p class="mt-1 whitespace-pre-line">{{ $selectedStep->review_notes }}</p>@if($selectedStep->correction_due_at)<p class="mt-2 font-semibold">{{ __('app.festival_correction_due_at') }}: {{ $selectedStep->correction_due_at->timezone($entry->edition->timezone)->format('d.m.Y H:i') }}</p>@endif</div>
                @elseif($selectedStep->review_notes)
                    <div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-700">{{ $selectedStep->review_notes }}</div>
                @endif

                @if($selectedStep->workflowStep->type === \App\Enums\FestivalWorkflowStepType::Application && $selectedState['mutable'])
                    <div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm"><div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"><span>{{ $entry->entry_name }} · {{ $entry->participants->map->displayName()->join(', ') }}</span><a href="{{ route('festival.portal.entries.edit', [$account->slug, $entry]) }}" class="font-semibold text-brand-700">{{ __('app.edit') }}</a></div></div>
                @endif

                <div class="mt-6 space-y-4">
                    @foreach($selectedStep->requirements as $requirement)
                        @php
                            $definition = $requirement->definition;
                            $inputType = $definition->input_type;
                            $subjectLabel = $requirement->participant?->displayName()
                                ?? ($definition->subject_scope === \App\Enums\FestivalFieldScope::Registrant ? $portalUser->displayName() : $entry->entry_name);
                            $latest = $requirement->submissions->first();
                            $currentValue = $latest?->value_json['value'] ?? null;
                        @endphp
                        <article class="rounded-xl border border-stone-200 p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div><strong>{{ $definition->name }}</strong><span class="ml-2 text-xs text-slate-500">{{ $subjectLabel }}</span>@if($definition->instructions)<p class="mt-1 text-sm text-slate-600">{{ $definition->instructions }}</p>@endif</div><span class="text-xs font-semibold text-slate-500">{{ __('app.festival_requirement_status_'.$requirement->status->value) }}</span></div>

                            @if($selectedState['mutable'])
                                @if($inputType === \App\Enums\FestivalRequirementInputType::File)
                                    <form method="POST" enctype="multipart/form-data" action="{{ route('festival.portal.submissions.store', [$account->slug, $entry, $requirement]) }}" class="mt-4 flex flex-col gap-2 sm:flex-row">@csrf<input type="file" name="file" @required($definition->is_required) class="crm-field"><x-ui.button type="submit">{{ __('app.upload') }}</x-ui.button></form>
                                @else
                                    <form method="POST" action="{{ route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $selectedStep, $requirement]) }}" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">@csrf
                                        <label class="grow"><span class="sr-only">{{ $definition->name }}</span>
                                            @if($inputType === \App\Enums\FestivalRequirementInputType::LongText)
                                                <textarea name="value" rows="4" @required($definition->is_required) class="crm-field">{{ is_scalar($currentValue) ? $currentValue : '' }}</textarea>
                                            @elseif($inputType === \App\Enums\FestivalRequirementInputType::Boolean)
                                                <select name="value" @required($definition->is_required) class="crm-field"><option value="">{{ __('app.select') }}</option><option value="1" @selected($currentValue === true || $currentValue === 1 || $currentValue === '1')>{{ __('app.yes') }}</option><option value="0" @selected($currentValue === false || $currentValue === 0 || $currentValue === '0')>{{ __('app.no') }}</option></select>
                                            @elseif(in_array($inputType, [\App\Enums\FestivalRequirementInputType::SingleSelect, \App\Enums\FestivalRequirementInputType::MultiSelect], true))
                                                <select name="value{{ $inputType === \App\Enums\FestivalRequirementInputType::MultiSelect ? '[]' : '' }}" @if($inputType === \App\Enums\FestivalRequirementInputType::MultiSelect) multiple @endif @required($definition->is_required) class="crm-field">@foreach(($definition->options ?? []) as $option)<option value="{{ $option['value'] }}" @selected(collect(is_array($currentValue) ? $currentValue : [$currentValue])->contains($option['value']))>{{ $option['label'] }}</option>@endforeach</select>
                                            @else
                                                <input type="{{ $inputType === \App\Enums\FestivalRequirementInputType::Integer ? 'number' : ($inputType === \App\Enums\FestivalRequirementInputType::Url ? 'url' : 'text') }}" name="value" value="{{ is_scalar($currentValue) ? $currentValue : '' }}" @required($definition->is_required) class="crm-field">
                                            @endif
                                        </label><x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
                                    </form>
                                @endif
                            @elseif($inputType !== \App\Enums\FestivalRequirementInputType::File && $latest)
                                <x-festivals.response-value :definition="$definition" :value="$currentValue" class="mt-3 block rounded-lg bg-slate-50 p-3 text-sm" />
                            @endif

                            @if($latest?->path)
                                <a href="{{ route('festival.portal.submissions.download', [$account->slug, $latest]) }}" class="mt-3 block break-all text-sm font-semibold text-brand-700">{{ __('app.download') }} · {{ $latest->original_name }}</a>
                            @endif
                        </article>
                    @endforeach
                </div>

                @if($selectedStep->charges->isNotEmpty())
                    <div class="mt-6 border-t border-stone-200 pt-5"><h3 class="text-lg font-semibold">{{ __('app.festival_payments') }}</h3><div class="mt-3 grid gap-3 md:grid-cols-2">@foreach($selectedStep->charges as $charge)<article class="rounded-xl bg-slate-50 p-4"><div class="flex items-start justify-between gap-3"><div><strong>{{ $charge->name }}</strong><p class="mt-1 text-xs text-slate-500">{{ __('app.festival_charge_status_'.$charge->status->value) }}</p></div><strong>{{ number_format($charge->amount_cents / 100, 2) }} {{ $charge->currency }}</strong></div>@if($selectedState['available'] && in_array($charge->status, [\App\Enums\FestivalChargeStatus::Pending, \App\Enums\FestivalChargeStatus::Failed], true) && $providers->isNotEmpty())<form method="POST" action="{{ route('festival.portal.charges.pay', [$account->slug, $entry, $charge]) }}" class="mt-3 flex gap-2">@csrf<select name="provider" class="crm-field">@foreach($providers as $provider)<option value="{{ $provider->provider->value }}">{{ config('integrations.providers.'.$provider->provider->value.'.label') }}</option>@endforeach</select><x-ui.button type="submit">{{ __('app.pay') }}</x-ui.button></form>@endif</article>@endforeach</div></div>
                @endif

                @if($entry->chargeAdjustments->where('status', 'pending')->isNotEmpty())
                    <div class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900"><strong>{{ __('app.festival_refund_pending') }}</strong><span class="ml-2">{{ number_format($entry->chargeAdjustments->where('status', 'pending')->sum('amount_cents') / 100, 2) }} {{ $entry->edition->currency }}</span></div>
                @endif

                @if($selectedState['mutable'])
                    <form method="POST" action="{{ route('festival.portal.entry-steps.submit', [$account->slug, $entry, $selectedStep]) }}" class="mt-6 flex justify-end">@csrf<x-ui.button type="submit" size="lg">{{ $selectedStep->workflowStep->review_mode === \App\Enums\FestivalWorkflowReviewMode::Organizer ? __('app.submit') : __('app.continue') }}</x-ui.button></form>
                @endif
            </section>
        @endif

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm"><h2 class="text-xl font-semibold">{{ __('app.festival_personal_schedule') }}</h2>@forelse($entry->scheduleSlots->whereNotNull('published_at') as $slot)<p class="mt-3">{{ $slot->starts_at->timezone($entry->edition->timezone)->format('d.m.Y H:i') }} · {{ $slot->stage->name }} · {{ __('app.festival_schedule_slot_type_'.$slot->type->value) }}</p>@empty<p class="mt-3 text-sm text-slate-500">{{ __('app.festival_schedule_pending') }}</p>@endforelse</section>
            <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm"><h2 class="text-xl font-semibold">{{ __('app.festival_results') }}</h2>@if($entry->result?->published_at)<p class="mt-4 text-3xl font-semibold">#{{ $entry->result->rank }} · {{ $entry->result->total_score }}</p><div class="mt-4 space-y-3">@foreach($entry->scoreSheets as $sheet)<article class="rounded-xl bg-slate-50 p-4"><strong>{{ $sheet->assignment->display_name }}</strong><span class="float-right font-semibold">{{ $sheet->total_score }}</span>@foreach($sheet->scores as $score)<p class="mt-2 text-sm">{{ $score->criterion->name }}: {{ $score->score }}@if($score->comment)<span class="block text-slate-500">{{ $score->comment }}</span>@endif</p>@endforeach</article>@endforeach</div>@else<p class="mt-3 text-sm text-slate-500">{{ __('app.festival_results_pending') }}</p>@endif</section>
        </div>

        @if (! in_array($entry->status, [\App\Enums\FestivalEntryStatus::Withdrawn, \App\Enums\FestivalEntryStatus::Rejected], true))
            <form method="POST" action="{{ route('festival.portal.entries.withdraw', [$account->slug, $entry]) }}" class="mt-6">@csrf<x-ui.button type="submit" variant="danger" size="lg" class="min-h-11">{{ __('app.festival_withdraw') }}</x-ui.button></form>
        @endif
    </div>
</main>
@endsection
