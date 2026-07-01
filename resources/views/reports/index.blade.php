@extends('layouts.app')

@section('title', __('app.reports').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.reports') }}</h1>
            <p class="crm-page-copy">{{ __('app.reports_copy') }}</p>
        </div>
    </div>

    <section class="mt-6 grid gap-4 lg:grid-cols-2">
        @foreach ($reports as $report)
            <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex items-start gap-4">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-violet-crm-100 text-violet-crm-700">
                        <x-ui.icon :name="$report['icon']" class="h-6 w-6" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold text-slate-950">{{ $report['title'] }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ $report['copy'] }}</p>
                        <x-ui.button :href="$report['href']" size="sm" class="mt-4">
                            {{ __('app.open_report') }}
                        </x-ui.button>
                    </div>
                </div>
            </article>
        @endforeach
    </section>
@endsection
