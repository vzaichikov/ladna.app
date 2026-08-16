@extends('layouts.app')

@section('title', __('app.edit_event_festival_staff').' - '.$user->name)

@section('content')
    <x-ui.page-header
        :title="__('app.edit_event_festival_staff')"
        :copy="$user->name"
    />

    <x-ui.panel class="mt-6 max-w-3xl">
        <x-event-festival-staff.form :$account :$membership :$user />
    </x-ui.panel>
@endsection
