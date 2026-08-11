@extends('layouts.app')

@section('title', ($rubric->exists ? __('app.festival_edit_rubric') : __('app.festival_add_rubric')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header
        :title="$rubric->exists ? __('app.festival_edit_rubric') : __('app.festival_add_rubric')"
        :copy="$rubric->exists ? __('app.festival_rubric_edit_warning') : __('app.festival_rubric_form_copy')"
    />

    <x-ui.panel class="max-w-5xl">
        <x-festivals.rubric-form :$account :$edition :$rubric :$categories />
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
