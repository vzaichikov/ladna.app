@extends('layouts.app')

@section('title', __('app.festival_classifications').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header><p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p><h1 class="crm-page-title mt-2">{{ __('app.festival_classifications') }}</h1><p class="crm-page-copy">{{ __('app.festival_classifications_page_copy') }}</p></header>
    <x-festivals.settings-help :title="__('app.festival_classifications_help_title')" :description="__('app.festival_classifications_help_copy')" :dependencies="[__('app.festival_classifications'), __('app.festival_categories'), __('app.festival_entries')]" />
    <div class="space-y-5">@foreach($axes as $axis)<x-festivals.taxonomy-axis :$account :$edition :$axis />@endforeach</div>
    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
        <h2 class="text-lg font-semibold">{{ __('app.festival_add_classification') }}</h2>
        <form method="POST" action="{{ route('dashboard.accounts.festivals.axes.store', [$account, $edition]) }}" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@csrf
            <label><span class="crm-label">{{ __('app.name') }}</span><input name="name" required class="crm-field"></label><label><span class="crm-label">{{ __('app.festival_axis_kind') }}</span><select name="kind" class="crm-field">@foreach(['style', 'age', 'level', 'entry_format', 'custom'] as $kind)<option value="{{ $kind }}">{{ __('app.festival_axis_kind_'.$kind) }}</option>@endforeach</select></label><label class="flex items-end gap-2 pb-3"><input type="checkbox" name="is_required" value="1" checked>{{ __('app.required') }}</label><div class="lg:col-span-3"><x-ui.button type="submit">{{ __('app.add') }}</x-ui.button></div>
        </form>
    </section>
</x-festivals.staff.workspace>
@endsection
