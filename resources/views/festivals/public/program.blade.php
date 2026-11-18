@extends('layouts.festival-public')

@section('festivalContent')
    <main class="festival-page min-h-screen">
        <div class="mx-auto max-w-5xl px-5 pt-8 pb-12 sm:px-8 sm:pt-12">
            <a href="{{ route('public.festivals.show', [$account->slug, $edition->slug]) }}" class="crm-focus festival-text inline-flex min-h-11 items-center gap-2 rounded-lg text-sm font-semibold hover:underline">
                <x-ui.icon name="arrow-left" class="h-4 w-4 shrink-0" />
                {{ __('app.festival_program_back') }}
            </a>
            <p class="festival-muted mt-4 text-sm font-semibold sm:text-base">{{ $edition->title }}</p>

            @include('festivals.public._program')
        </div>
    </main>
@endsection
