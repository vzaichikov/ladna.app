@extends('layouts.app')

@section('title', ($fee->exists ? __('app.festival_edit_fee') : __('app.festival_add_fee')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$fee->exists ? __('app.festival_edit_fee') : __('app.festival_add_fee')" :copy="__('app.festival_fee_form_copy')" />
    <x-ui.panel><x-festivals.fee-form :$account :$edition :$fee /></x-ui.panel>
</x-festivals.staff.workspace>
@endsection
