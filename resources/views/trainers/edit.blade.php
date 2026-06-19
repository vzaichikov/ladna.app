@extends('layouts.app')

@section('title', __('app.edit').' '.$trainer->name)

@section('content')
    <h1 class="crm-page-title">{{ __('app.edit') }} {{ $trainer->name }}</h1>
    <p class="crm-page-copy">{{ $account->name }}</p>
    <form method="POST" action="{{ route('dashboard.accounts.trainers.update', [$account, $trainer]) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')
        @include('trainers.form-fields')
        <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
    </form>
@endsection
