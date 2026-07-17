@extends('layouts.app')

@section('title', __('app.salary_models').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.salary_models') }}</h1>
            <p class="crm-page-copy">{{ __('app.salary_models_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.reports.trainers', $account)" variant="secondary">
            {{ __('app.trainer_report_title') }}
        </x-ui.button>
    </div>

    <x-ui.panel class="mt-6">
        <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                <x-ui.icon name="triangle-alert" class="h-5 w-5" />
            </span>
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.salary_models_not_configured') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('app.salary_models_not_configured_copy') }}</p>
            </div>
        </div>
    </x-ui.panel>
@endsection
