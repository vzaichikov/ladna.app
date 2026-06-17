@extends('layouts.app')

@section('title', __('app.create_class_pass_plan').' - '.$account->name)

@section('content')
    <h1 class="crm-page-title">{{ __('app.create_class_pass_plan') }}</h1>
    <p class="crm-page-copy">{{ $account->name }}</p>

    <form method="POST" action="{{ route('dashboard.accounts.class-pass-plans.store', $account) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-crm">
        @csrf
        @include('class-pass-plans.form-fields')
        <x-ui.button type="submit">
            {{ __('app.create_class_pass_plan') }}
        </x-ui.button>
    </form>
@endsection
