@extends('layouts.festival-portal')

@section('title', __('app.festival_judging').' - '.$edition->title)

@section('content')
<main class="min-h-screen bg-canvas px-5 py-8"><div class="mx-auto max-w-6xl">@include('festivals.portal._nav')<header class="mt-8"><p class="text-sm font-semibold text-brand-700">{{ $edition->title }}</p><h1 class="mt-1 text-4xl font-semibold">{{ __('app.festival_judging') }}</h1><p class="mt-2 text-slate-600">{{ $assignment->display_name }}</p></header><div class="mt-6 grid gap-4 md:grid-cols-2">@forelse($sheets as $sheet)<a href="{{ route('festival.portal.judging.edit', [$account->slug, $edition->slug, $sheet]) }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm"><strong class="text-xl">{{ $sheet->entry->entry_name }}</strong><span class="mt-2 block text-sm text-slate-500">{{ $sheet->entry->category->name }} · {{ __('app.festival_score_sheet_status_'.$sheet->status->value) }}</span></a>@empty<x-ui.empty-state icon="clipboard-check">{{ __('app.festival_score_sheets_empty') }}</x-ui.empty-state>@endforelse</div></div></main>
@endsection
