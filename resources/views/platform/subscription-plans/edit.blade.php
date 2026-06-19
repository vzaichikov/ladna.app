@extends('layouts.app')

@section('title', __('app.edit').' '.$plan->name)

@section('content')
    <div class="crm-page-kicker">{{ __('app.platform') }}</div>
    <h1 class="crm-page-title">{{ __('app.edit') }} {{ $plan->name }}</h1>

    <form method="POST" action="{{ route('platform.subscription-plans.update', $plan) }}" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')
        @include('platform.subscription-plans.form-fields')
        <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
    </form>
@endsection
