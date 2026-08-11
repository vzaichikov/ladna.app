@extends('layouts.app')

@section('title', ($requirement->exists ? __('app.festival_edit_registration_field') : __('app.festival_add_registration_field')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$requirement->exists ? __('app.festival_edit_registration_field') : __('app.festival_add_registration_field')" :copy="__('app.festival_registration_field_form_copy')" />
    <x-ui.panel><x-festivals.requirement-form :$account :$edition :$requirement /></x-ui.panel>
</x-festivals.staff.workspace>
@endsection
