@extends('layouts.public')

@section('title', $entry->performer_name.' - '.$entry->edition->title)

@section('content')
<main class="min-h-screen bg-canvas px-5 py-8">
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')
        <header class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="font-mono text-sm text-slate-500">{{ $entry->code }}</p>
                <h1 class="mt-1 text-4xl font-semibold">{{ $entry->performer_name }}</h1>
                <p class="mt-2 text-slate-600">{{ $entry->edition->title }} · {{ $entry->category->name }} · {{ $entry->status->value }}</p>
            </div>
            @if ($entry->status === \App\Enums\FestivalEntryStatus::Draft)
                <div class="flex gap-2">
                    <x-ui.button :href="route('festival.portal.entries.edit', [$account->slug, $entry])" variant="secondary">{{ __('app.edit') }}</x-ui.button>
                    <form method="POST" action="{{ route('festival.portal.entries.submit', [$account->slug, $entry]) }}">@csrf<x-ui.button type="submit">{{ __('app.submit') }}</x-ui.button></form>
                </div>
            @endif
        </header>

        @if (session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="mt-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $errors->first() }}</div>@endif

        <div class="mt-7 grid gap-6 lg:grid-cols-2">
            <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
                <h2 class="text-xl font-semibold">{{ __('app.festival_checklist') }}</h2>
                <div class="mt-4 space-y-4">
                    @forelse ($entry->requirements as $requirement)
                        <article class="rounded-xl border border-stone-200 p-4">
                            <div class="flex justify-between gap-3">
                                <div><strong>{{ $requirement->definition_snapshot['name'] }}</strong><p class="mt-1 text-xs text-slate-500">{{ $requirement->definition_snapshot['type'] }} · {{ $requirement->status->value }}</p></div>
                                @if ($requirement->due_at)<time class="text-xs text-slate-500">{{ $requirement->due_at->timezone($entry->edition->timezone)->format('d.m H:i') }}</time>@endif
                            </div>
                            @if (! in_array($requirement->status, [\App\Enums\FestivalRequirementStatus::Accepted, \App\Enums\FestivalRequirementStatus::Waived], true))
                                <form method="POST" enctype="multipart/form-data" action="{{ route('festival.portal.submissions.store', [$account->slug, $entry, $requirement]) }}" class="mt-3 flex flex-col gap-2 sm:flex-row">@csrf<input type="file" name="file" required class="crm-field"><x-ui.button type="submit">{{ __('app.upload') }}</x-ui.button></form>
                            @endif
                            @if ($requirement->submissions->isNotEmpty())
                                <div class="mt-3 space-y-1">
                                    @foreach ($requirement->submissions as $submission)
                                        <a href="{{ route('festival.portal.submissions.download', [$account->slug, $submission]) }}" class="block text-sm font-semibold text-brand-700">v{{ $submission->version }} · {{ $submission->original_name }} · {{ $submission->status->value }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_no_requirements') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
                <h2 class="text-xl font-semibold">{{ __('app.festival_payments') }}</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($entry->charges as $charge)
                        <article class="rounded-xl border border-stone-200 p-4">
                            <div class="flex items-start justify-between gap-3"><div><strong>{{ $charge->name }}</strong><p class="mt-1 text-xs text-slate-500">{{ $charge->status->value }}</p></div><strong>{{ number_format($charge->amount_cents / 100, 2) }} {{ $charge->currency }}</strong></div>
                            @if (in_array($charge->status, [\App\Enums\FestivalChargeStatus::Pending, \App\Enums\FestivalChargeStatus::Failed], true) && $providers->isNotEmpty())
                                <form method="POST" action="{{ route('festival.portal.charges.pay', [$account->slug, $entry, $charge]) }}" class="mt-3 flex gap-2">@csrf<select name="provider" class="crm-field">@foreach($providers as $provider)<option value="{{ $provider->provider->value }}">{{ config('integrations.providers.'.$provider->provider->value.'.label') }}</option>@endforeach</select><x-ui.button type="submit">{{ __('app.pay') }}</x-ui.button></form>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_no_payments') }}</p>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm"><h2 class="text-xl font-semibold">{{ __('app.festival_personal_schedule') }}</h2>@forelse($entry->scheduleSlots->whereNotNull('published_at') as $slot)<p class="mt-3">{{ $slot->starts_at->timezone($entry->edition->timezone)->format('d.m.Y H:i') }} · {{ $slot->stage->name }} · {{ $slot->type->value }}</p>@empty<p class="mt-3 text-sm text-slate-500">{{ __('app.festival_schedule_pending') }}</p>@endforelse</section>
            <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm"><h2 class="text-xl font-semibold">{{ __('app.festival_results') }}</h2>@if($entry->result?->published_at)<p class="mt-4 text-3xl font-semibold">#{{ $entry->result->rank }} · {{ $entry->result->total_score }}</p><div class="mt-4 space-y-3">@foreach($entry->scoreSheets as $sheet)<article class="rounded-xl bg-slate-50 p-4"><strong>{{ $sheet->assignment->display_name }}</strong><span class="float-right font-semibold">{{ $sheet->total_score }}</span>@foreach($sheet->scores as $score)<p class="mt-2 text-sm">{{ $score->criterion->name }}: {{ $score->score }}@if($score->comment)<span class="block text-slate-500">{{ $score->comment }}</span>@endif</p>@endforeach</article>@endforeach</div>@else<p class="mt-3 text-sm text-slate-500">{{ __('app.festival_results_pending') }}</p>@endif</section>
        </div>

        @if (! in_array($entry->status, [\App\Enums\FestivalEntryStatus::Withdrawn, \App\Enums\FestivalEntryStatus::Rejected], true))
            <form method="POST" action="{{ route('festival.portal.entries.withdraw', [$account->slug, $entry]) }}" class="mt-6">@csrf<button type="submit" class="text-sm font-semibold text-rose-700">{{ __('app.festival_withdraw') }}</button></form>
        @endif
    </div>
</main>
@endsection
