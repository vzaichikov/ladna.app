@extends('layouts.app')

@section('title', __('app.edit').' '.$location->name)

@section('content')
    <x-ui.page-header :title="__('app.edit').' '.$location->name" />

    <form method="POST" action="{{ route('dashboard.accounts.locations.update', [$account, $location]) }}" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')
        @include('locations.form-fields')
        <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
    </form>
@endsection
