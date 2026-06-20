@extends('layouts.app')

@section('title', __('app.my_brand').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.my_brand') }}</h1>
            <p class="crm-page-copy">{{ __('app.business_details_copy') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('dashboard.accounts.update', $account) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')

        @include('accounts.form-fields')

        <x-ui.button type="submit">
            <x-ui.icon name="edit" class="h-4 w-4" />
            {{ __('app.save') }}
        </x-ui.button>
    </form>
@endsection
