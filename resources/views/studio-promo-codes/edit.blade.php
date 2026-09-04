@extends('layouts.app')

@section('title', __('app.edit_promo_code').' - '.$account->name)

@section('content')
    <h1 class="crm-page-title">{{ __('app.edit_promo_code') }}</h1>
    <p class="crm-page-copy">{{ $studioPromoCode->name }}</p>

    <form method="POST" action="{{ route('dashboard.accounts.promo-codes.update', [$account, $studioPromoCode]) }}" class="mt-6 max-w-4xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')
        @include('studio-promo-codes.form-fields')
        <div class="flex flex-wrap gap-3">
            <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.promo-codes.index', $account)" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
        </div>
    </form>
@endsection
