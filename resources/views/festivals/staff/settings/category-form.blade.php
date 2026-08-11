@extends('layouts.app')

@section('title', ($category->exists ? __('app.festival_edit_category') : __('app.festival_add_category')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header
        :title="$category->exists ? __('app.festival_edit_category') : __('app.festival_add_category')"
        :copy="__('app.festival_category_form_copy')"
    />

    @if($directions->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
            {{ __('app.festival_category_direction_required_copy') }}
            <a href="{{ route('dashboard.accounts.festivals.settings.directions', [$account, $edition]) }}" class="font-semibold underline">{{ __('app.festival_manage_directions') }}</a>
        </div>
    @endif

    <x-festivals.category-form :$account :$edition :$category :$directions :$workflows />
</x-festivals.staff.workspace>
@endsection
