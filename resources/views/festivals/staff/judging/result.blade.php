@extends('layouts.app')

@section('title', __('app.festival_results').' · '.$category->name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_results').' · '.$category->name" :copy="__('app.festival_results_realtime_copy')"><x-slot:actions><x-ui.button :href="route('dashboard.accounts.festivals.judging.results.table', [$account, $edition, $category])">{{ __('app.festival_result_table') }}</x-ui.button></x-slot:actions></x-ui.page-header>

    @include('festivals.staff.judging._result-list')
</x-festivals.staff.workspace>
@endsection
