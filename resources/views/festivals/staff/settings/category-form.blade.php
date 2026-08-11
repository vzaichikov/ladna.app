@extends('layouts.app')

@section('title', ($category->exists ? __('app.festival_edit_category') : __('app.festival_add_category')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header>
        <a href="{{ route('dashboard.accounts.festivals.settings.categories', [$account, $edition]) }}" class="inline-flex items-center gap-2 text-sm font-semibold text-brand-700 hover:text-brand-800">← {{ __('app.festival_categories') }}</a>
        <p class="crm-page-kicker mt-5">{{ __('app.festival_tab_settings') }}</p>
        <h1 class="crm-page-title mt-2">{{ $category->exists ? __('app.festival_edit_category') : __('app.festival_add_category') }}</h1>
        <p class="crm-page-copy">{{ __('app.festival_category_form_copy') }}</p>
    </header>

    @if($directions->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
            {{ __('app.festival_category_direction_required_copy') }}
            <a href="{{ route('dashboard.accounts.festivals.settings.directions', [$account, $edition]) }}" class="font-semibold underline">{{ __('app.festival_manage_directions') }}</a>
        </div>
    @endif

    <x-festivals.category-form :$account :$edition :$category :$directions :$workflows />
</x-festivals.staff.workspace>
@endsection
