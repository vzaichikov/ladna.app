@extends('layouts.app')

@section('title', __('app.festival_result_table').' · '.$category->name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_result_table').' · '.$category->name" :copy="__('app.festival_result_table_copy')">
        <x-slot:actions><x-ui.button :href="route('dashboard.accounts.festivals.judging.results.show', [$account, $edition, $category])" variant="secondary">{{ __('app.festival_result_cards') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>
    @include('festivals.shared.result-table._content')
</x-festivals.staff.workspace>
@endsection
