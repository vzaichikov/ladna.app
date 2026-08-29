@extends('layouts.festival-portal')

@section('title', __('app.festival_nomination_assignments').' - '.$edition->title)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8"><div class="mx-auto max-w-6xl space-y-6">@include('festivals.portal._nav')<header class="mt-8"><p class="text-sm font-semibold text-brand-700">{{ $edition->title }}</p><h1 class="mt-1 text-3xl font-semibold sm:text-4xl">{{ __('app.festival_nomination_assignments') }}</h1><p class="mt-2 text-slate-600">{{ __('app.festival_nomination_assignments_copy') }}</p></header>@include('festivals.shared._nomination-assignments')</div></main>
@endsection
