@extends('layouts.festival-portal')

@section('title', __('app.festival_judge_cabinet').' - '.$account->name)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')
        <header class="mt-8"><h1 class="text-3xl font-semibold sm:text-4xl">{{ __('app.festival_judge_cabinet') }}</h1><p class="mt-2 text-slate-600">{{ __('app.festival_judge_cabinet_copy', ['name' => $portalUser->displayName()]) }}</p></header>
        @if(session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
        <section class="mt-7 grid gap-4 md:grid-cols-2">
            @forelse ($assignments as $assignment)
                <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                    <p class="text-sm font-semibold text-brand-700">{{ $assignment->edition->series->name }}</p>
                    <h2 class="mt-1 text-xl font-semibold">{{ $assignment->edition->title }}</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ $assignment->categories->pluck('name')->join(', ') }}</p>
                    <div class="mt-4 flex flex-wrap gap-2"><x-ui.button :href="route('festival.portal.judging.index', [$account->slug, $assignment->edition->slug])">{{ __('app.festival_judging') }}</x-ui.button><x-ui.button :href="route('festival.portal.battle-votes.index', [$account->slug, $assignment->edition->slug])" variant="secondary">{{ __('app.festival_battle_voting') }}</x-ui.button></div>
                </article>
            @empty
                <x-ui.empty-state :title="__('app.festival_judge_assignments_empty')" icon="clipboard-check" />
            @endforelse
        </section>
    </div>
</main>
@endsection
