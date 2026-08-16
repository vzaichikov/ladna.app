@extends('layouts.app')

@section('title', __('app.add_event_festival_staff').' - '.$account->name)

@section('content')
    <x-ui.page-header
        :title="__('app.add_event_festival_staff')"
        :copy="__('app.event_festival_staff_form_copy')"
    />

    <x-ui.panel class="mt-6 max-w-3xl">
        <x-event-festival-staff.form :$account :$membership :$user />
    </x-ui.panel>
@endsection
