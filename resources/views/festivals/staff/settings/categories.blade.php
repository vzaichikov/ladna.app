@extends('layouts.app')

@section('title', __('app.festival_categories').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header><p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p><h1 class="crm-page-title mt-2">{{ __('app.festival_categories') }}</h1><p class="crm-page-copy">{{ __('app.festival_categories_page_copy') }}</p></header>
    <x-festivals.settings-help :title="__('app.festival_categories_help_title')" :description="__('app.festival_categories_help_copy')" :dependencies="[__('app.festival_taxonomy_directions'), __('app.festival_classifications'), __('app.festival_categories'), __('app.festival_registration_workflows'), __('app.festival_entries')]" />
    <div class="space-y-4">
        @foreach($edition->categories as $category)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"><div><h2 class="text-lg font-semibold">{{ $category->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ $category->options->pluck('label')->join(' · ') ?: __('app.festival_no_classification_selected') }} · {{ trans_choice('app.festival_entry_usage_count', $category->entries_count, ['count' => $category->entries_count]) }}</p></div><x-festivals.settings-actions :active="$category->is_active" :toggle-route="route('dashboard.accounts.festivals.categories.toggle', [$account, $edition, $category])" :move-route="route('dashboard.accounts.festivals.categories.move', [$account, $edition, $category])" /></div>
                <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('app.edit') }}</summary><div class="mt-4"><x-festivals.category-form :$account :$edition :$category /></div></details>
            </article>
        @endforeach
    </div>
    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm"><h2 class="text-lg font-semibold">{{ __('app.festival_add_category') }}</h2><div class="mt-4"><x-festivals.category-form :$account :$edition /></div></section>
</x-festivals.staff.workspace>
@endsection
