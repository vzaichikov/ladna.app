@extends('layouts.app')

@section('title', __('app.create_price_version').' - '.$plan->name)

@section('content')
    <div class="crm-page-kicker">{{ $plan->name }}</div>
    <h1 class="crm-page-title">{{ __('app.create_price_version') }}</h1>
    <form method="POST" action="{{ route('platform.subscription-plans.price-versions.store', $plan) }}" class="mt-6 max-w-4xl rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @include('platform.subscription-price-versions.form')
        <x-ui.button type="submit" class="mt-6">{{ __('app.save_draft_and_preview') }}</x-ui.button>
    </form>
@endsection
