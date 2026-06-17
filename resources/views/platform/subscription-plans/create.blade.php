@extends('layouts.app')

@section('title', __('app.create_subscription_plan').' - '.__('app.platform'))

@section('content')
    <div class="crm-page-kicker">{{ __('app.platform') }}</div>
    <h1 class="crm-page-title">{{ __('app.create_subscription_plan') }}</h1>

    <form method="POST" action="{{ route('platform.subscription-plans.store') }}" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-crm">
        @csrf
        @include('platform.subscription-plans.form-fields')
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-violet-crm-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-crm-700">{{ __('app.create_subscription_plan') }}</button>
    </form>
@endsection
