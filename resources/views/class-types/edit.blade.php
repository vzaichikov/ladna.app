@extends('layouts.app')

@section('title', __('app.edit').' '.$classType->name)

@section('content')
    <h1 class="crm-page-title">{{ __('app.edit') }} {{ $classType->name }}</h1>
    <p class="crm-page-copy">{{ __('app.'.$scheduleKindDefinition['title_key']) }} · {{ $account->name }}</p>
    <form method="POST" action="{{ route(\App\Support\ScheduleKindRegistry::routeName($scheduleKind, 'update'), [$account, $classType]) }}" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')
        @include('class-types.form-fields')
        <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
    </form>
@endsection
