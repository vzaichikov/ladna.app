@extends('layouts.app')

@section('title', __('app.my_account').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.my_account') }}</h1>
            <p class="crm-page-copy">{{ __('app.account_details_copy') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('dashboard.accounts.owner-profile.update', $account) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')

        @include('profile.form-fields')
    </form>
@endsection
