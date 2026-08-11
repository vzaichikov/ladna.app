@extends('layouts.app')

@section('title', __('app.festival_tab_judging_results').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_judging_title')" :copy="__('app.festival_judging_copy')" />

    @can('manageFestivals', $account)
        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <h2 class="text-xl font-semibold">{{ __('app.festival_judges') }}</h2>
                <div class="mt-4 space-y-2">
                    @forelse ($assignments as $judge)
                        <p class="rounded-xl bg-slate-50 p-3"><strong>{{ $judge->display_name }}</strong><span class="block text-xs text-slate-500">{{ $judge->categories->pluck('name')->join(', ') }}</span></p>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_judges_empty') }}</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.judges.store', [$account, $edition]) }}" class="mt-5 space-y-3">
                    @csrf
                    <input name="display_name" required placeholder="{{ __('app.name') }}" class="crm-field">
                    <input type="number" name="user_id" placeholder="{{ __('app.festival_staff_user_id') }}" class="crm-field">
                    <input type="number" name="festival_portal_user_id" placeholder="{{ __('app.festival_guest_portal_user_id') }}" class="crm-field">
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($edition->categories as $category)
                            <label class="flex items-center gap-2 rounded-lg border border-stone-200 p-3"><input type="checkbox" name="category_ids[]" value="{{ $category->id }}" class="crm-checkbox">{{ $category->name }}</label>
                        @endforeach
                    </div>
                    <x-ui.button type="submit">{{ __('app.add') }}</x-ui.button>
                </form>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <h2 class="text-xl font-semibold">{{ __('app.festival_rubrics') }}</h2>
                <div class="mt-4 space-y-2">
                    @forelse ($rubrics as $rubric)
                        <article class="rounded-xl bg-slate-50 p-3"><strong>{{ $rubric->name }}</strong><span class="block text-xs text-slate-500">{{ $rubric->category?->name ?? __('app.all') }} · {{ $rubric->is_active ? __('app.active') : __('app.inactive') }}</span><details class="mt-3"><summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('app.edit') }}</summary><div class="mt-3"><x-festivals.rubric-form :$account :$edition :$rubric :categories="$edition->categories" /></div></details></article>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_rubrics_empty') }}</p>
                    @endforelse
                </div>
                <p class="mt-5 text-sm text-slate-600">{{ __('app.festival_rubric_form_copy') }}</p>
                <div class="mt-3"><x-festivals.rubric-form :$account :$edition :categories="$edition->categories" /></div>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.score-sheets.prepare', [$account, $edition]) }}" class="mt-3">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">{{ __('app.festival_prepare_score_sheets') }}</x-ui.button>
                </form>
            </section>
        </div>

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <h2 class="text-xl font-semibold">{{ __('app.festival_results') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_results_publish_copy') }}</p>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($edition->categories as $category)
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.results.publish', [$account, $edition, $category]) }}" class="flex items-center justify-between gap-3 rounded-xl border border-stone-200 p-4">
                        @csrf
                        <strong>{{ $category->name }}</strong>
                        <x-ui.button type="submit" size="sm" variant="secondary">{{ __('app.publish') }}</x-ui.button>
                    </form>
                @endforeach
            </div>
        </section>
    @endcan

    @if ($assignment)
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <h2 class="text-xl font-semibold">{{ __('app.festival_my_score_sheets') }}</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                @forelse ($sheets as $sheet)
                    <a href="{{ route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $edition, $sheet]) }}" class="rounded-xl border border-stone-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/40"><strong>{{ $sheet->entry->entry_name }}</strong><span class="mt-1 block text-sm text-slate-500">{{ $sheet->entry->category->name }} · {{ __('app.festival_score_sheet_status_'.$sheet->status->value) }} · {{ $sheet->total_score }}</span></a>
                @empty
                    <div class="md:col-span-2"><x-ui.empty-state icon="clipboard-check">{{ __('app.festival_score_sheets_empty') }}</x-ui.empty-state></div>
                @endforelse
            </div>
        </section>
    @endif
</x-festivals.staff.workspace>
@endsection
