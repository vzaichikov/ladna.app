@extends('layouts.app')

@section('title', __('app.create_class_pass_segment').' - '.$account->name)

@section('content')
    <h1 class="crm-page-title">{{ __('app.create_class_pass_segment') }}</h1>
    <p class="crm-page-copy">{{ $account->name }}</p>
    <form method="POST" action="{{ route('dashboard.accounts.class-pass-segments.store', $account) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @include('class-pass-segments.form-fields')
        <x-ui.button type="submit">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_class_pass_segment') }}
        </x-ui.button>
    </form>
@endsection
