@extends('layouts.app')

@section('title', __('app.create_activity_direction').' - '.$account->name)

@section('content')
    <h1 class="crm-page-title">{{ __('app.create_activity_direction') }}</h1>
    <p class="crm-page-copy">{{ $account->name }}</p>
    <form method="POST" action="{{ route('dashboard.accounts.activity-directions.store', $account) }}" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-crm">
        @csrf
        @include('activity-directions.form-fields')
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-violet-crm-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-crm-700">{{ __('app.create_activity_direction') }}</button>
    </form>
@endsection
