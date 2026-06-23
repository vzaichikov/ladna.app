@extends('layouts.app')

@section('title', __('app.'.$scheduleKindDefinition['create_key']).' - '.$account->name)

@section('content')
    <h1 class="crm-page-title">{{ __('app.'.$scheduleKindDefinition['create_key']) }}</h1>
    <p class="crm-page-copy">{{ __('app.'.$scheduleKindDefinition['title_key']) }} · {{ $account->name }}</p>
    <form method="POST" action="{{ route(\App\Support\ScheduleKindRegistry::routeName($scheduleKind, 'store'), $account) }}" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @include('class-types.form-fields')
        <x-ui.button type="submit">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.'.$scheduleKindDefinition['create_key']) }}
        </x-ui.button>
    </form>
@endsection
