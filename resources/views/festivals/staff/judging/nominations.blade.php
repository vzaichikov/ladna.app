@extends('layouts.app')

@section('title', __('app.festival_nomination_assignments').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_nomination_assignments')" :copy="__('app.festival_nomination_assignments_copy')" />
    @include('festivals.shared._nomination-assignments')
</x-festivals.staff.workspace>
@endsection
