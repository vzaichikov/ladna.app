@extends('layouts.festival-portal')

@section('title', __('app.festival_judging').' - '.$edition->title)

@section('content')
<main class="min-h-screen bg-canvas px-5 py-8">
    <div class="mx-auto max-w-6xl space-y-6">
        @include('festivals.portal._nav')

        <header class="mt-8 flex flex-wrap items-end justify-between gap-4">
            <div><p class="text-sm font-semibold text-brand-700">{{ $edition->title }}</p><h1 class="mt-1 text-4xl font-semibold">{{ __('app.festival_judging') }}</h1><p class="mt-2 text-slate-600">{{ $assignment->display_name }}</p></div>
            @if($assignment->is_head_judge)<div class="flex flex-wrap gap-2"><x-ui.button :href="route('festival.portal.judging.results.nominations.index', [$account->slug, $edition])" variant="secondary">{{ __('app.festival_nomination_assignments') }}</x-ui.button>@foreach($assignment->categories as $category)@if($category->competition_format->value === 'scored')<x-ui.button :href="route('festival.portal.judging.results.table', [$account->slug, $edition, $category])" variant="secondary">{{ __('app.festival_result_table') }} · {{ $category->name }}</x-ui.button>@endif @endforeach</div>@endif
        </header>

        @include('festivals.shared._judge-list')
    </div>
</main>
@endsection
