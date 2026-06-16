@extends('layouts.app')

@section('title', __('app.create_account').' - '.__('app.platform'))

@section('content')
    <div>
        <div class="crm-page-kicker">{{ __('app.platform') }}</div>
        <h1 class="crm-page-title">{{ __('app.create_account') }}</h1>
    </div>

    <form method="POST" action="{{ route('platform.accounts.store') }}" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-crm">
        @csrf
        @include('platform.accounts.form-fields')
        <x-ui.button type="submit">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_account') }}
        </x-ui.button>
    </form>
@endsection
