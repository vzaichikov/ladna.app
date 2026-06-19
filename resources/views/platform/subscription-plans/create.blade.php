@extends('layouts.app')

@section('title', __('app.create_subscription_plan').' - '.__('app.platform'))

@section('content')
    <div class="crm-page-kicker">{{ __('app.platform') }}</div>
    <h1 class="crm-page-title">{{ __('app.create_subscription_plan') }}</h1>

    <form method="POST" action="{{ route('platform.subscription-plans.store') }}" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @include('platform.subscription-plans.form-fields')
        <x-ui.button type="submit">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_subscription_plan') }}
        </x-ui.button>
    </form>
@endsection
