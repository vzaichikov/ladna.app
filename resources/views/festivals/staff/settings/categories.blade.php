@extends('layouts.app')

@section('title', __('app.festival_categories').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p>
            <h1 class="crm-page-title mt-2">{{ __('app.festival_categories') }}</h1>
            <p class="crm-page-copy">{{ __('app.festival_categories_page_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.festivals.categories.create', [$account, $edition])">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.festival_add_category') }}
        </x-ui.button>
    </header>

    <x-festivals.settings-help
        :title="__('app.festival_categories_help_title')"
        :description="__('app.festival_categories_help_copy')"
        :dependencies="[__('app.festival_taxonomy_directions'), __('app.festival_categories'), __('app.festival_registration_workflows'), __('app.festival_registration_fields'), __('app.festival_entries')]"
    />

    <div class="space-y-4">
        @forelse($edition->categories as $category)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-950">{{ $category->name }}</h2>
                            @unless($category->is_active)
                                <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>
                            @endunless
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ $category->direction->name }} · {{ trans_choice('app.festival_entry_usage_count', $category->entries_count, ['count' => $category->entries_count]) }}
                        </p>
                        <dl class="mt-4 flex flex-wrap gap-2 text-xs text-slate-700">
                            <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_roster') }}</dt><dd>{{ __('app.festival_participants_range', ['min' => $category->min_members, 'max' => $category->max_members]) }}</dd></div>
                            @if($category->min_age !== null || $category->max_age !== null)
                                <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_age_limits') }}</dt><dd>{{ __('app.festival_age_range', ['min' => $category->min_age ?? '—', 'max' => $category->max_age ?? '—']) }}</dd></div>
                            @endif
                            @if($category->min_duration_seconds !== null || $category->max_duration_seconds !== null)
                                <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_performance_duration') }}</dt><dd>{{ __('app.festival_duration_range', ['min' => $category->min_duration_seconds ?? '—', 'max' => $category->max_duration_seconds ?? '—']) }}</dd></div>
                            @endif
                            @if($category->registration_closes_at)
                                <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_registration_closes_at') }}</dt><dd>{{ __('app.festival_category_deadline_value', ['date' => $category->registration_closes_at->timezone($edition->timezone)->format('d.m.Y H:i'), 'timezone' => $edition->timezone]) }}</dd></div>
                            @endif
                        </dl>
                    </div>
                    <x-festivals.settings-actions
                        :active="$category->is_active"
                        :toggle-route="route('dashboard.accounts.festivals.categories.toggle', [$account, $edition, $category])"
                        :move-route="route('dashboard.accounts.festivals.categories.move', [$account, $edition, $category])"
                        :edit-route="route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category])"
                        class="lg:justify-end"
                    />
                </div>
            </article>
        @empty
            <section class="rounded-2xl border border-dashed border-stone-300 bg-white p-8 text-center shadow-crm">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_categories_empty') }}</h2>
                <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">{{ __('app.festival_categories_empty_copy') }}</p>
                <x-ui.button :href="route('dashboard.accounts.festivals.categories.create', [$account, $edition])" class="mt-5">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.festival_add_category') }}
                </x-ui.button>
            </section>
        @endforelse
    </div>
</x-festivals.staff.workspace>
@endsection
