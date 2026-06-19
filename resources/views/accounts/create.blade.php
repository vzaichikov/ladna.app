@extends('layouts.app')

@section('title', __('app.create_account').' - '.__('app.app_name'))

@section('content')
    <h1 class="crm-page-title">{{ __('app.create_account') }}</h1>

    <form method="POST" action="{{ route('dashboard.accounts.store') }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @include('accounts.form-fields')
        <x-ui.button type="submit">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_account') }}
        </x-ui.button>
    </form>
@endsection
