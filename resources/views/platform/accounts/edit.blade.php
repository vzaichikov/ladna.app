@extends('layouts.app')

@section('title', __('app.edit').' '.$account->name)

@section('content')
    <div>
        <div class="crm-page-kicker">{{ __('app.platform') }}</div>
        <h1 class="crm-page-title">{{ __('app.edit') }} {{ $account->name }}</h1>
    </div>

    <form method="POST" action="{{ route('platform.accounts.update', $account) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')
        @include('platform.accounts.form-fields')
        <x-ui.button type="submit">
            <x-ui.icon name="edit" class="h-4 w-4" />
            {{ __('app.edit') }}
        </x-ui.button>
    </form>
@endsection
