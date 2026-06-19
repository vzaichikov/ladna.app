@extends('layouts.app')

@section('title', __('app.create_activity_direction').' - '.$account->name)

@section('content')
    <h1 class="crm-page-title">{{ __('app.create_activity_direction') }}</h1>
    <p class="crm-page-copy">{{ $account->name }}</p>
    <form method="POST" action="{{ route('dashboard.accounts.activity-directions.store', $account) }}" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @include('activity-directions.form-fields')
        <x-ui.button type="submit">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_activity_direction') }}
        </x-ui.button>
    </form>
@endsection
