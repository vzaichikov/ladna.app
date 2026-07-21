@extends('layouts.app')

@section('title', __('app.edit_price_version').' - '.$plan->name)

@section('content')
    <div class="crm-page-kicker">{{ $plan->name }}</div>
    <h1 class="crm-page-title">{{ __('app.edit_price_version', ['version' => $priceVersion->version]) }}</h1>
    <form method="POST" action="{{ route('platform.subscription-plans.price-versions.update', [$plan, $priceVersion]) }}" class="mt-6 max-w-4xl rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')
        @include('platform.subscription-price-versions.form')
        <x-ui.button type="submit" class="mt-6">{{ __('app.save_draft_and_preview') }}</x-ui.button>
    </form>
@endsection
