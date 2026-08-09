@extends('layouts.app')

@section('title', __('app.festival_tab_judging_results').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <div>
        <p class="crm-page-kicker">{{ __('app.festival_tab_judging_results') }}</p>
        <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('app.festival_judging_title') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_judging_copy') }}</p>
    </div>

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
                <form method="POST" action="{{ route('dashboard.accounts.festivals.judges.store', [$account, $edition]) }}" class="mt-5 space-y-3">
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
                        <p class="rounded-xl bg-slate-50 p-3"><strong>{{ $rubric->name }}</strong><span class="block text-xs text-slate-500">v{{ $rubric->version }} · {{ $rubric->category?->name ?? __('app.all') }}</span></p>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_rubrics_empty') }}</p>
                    @endforelse
                </div>
                <p class="mt-5 text-sm text-slate-600">{{ __('app.festival_rubric_form_copy') }}</p>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.rubrics.store', [$account, $edition]) }}" class="mt-3 space-y-3">
                    @csrf
                    <input name="name" required placeholder="{{ __('app.name') }}" class="crm-field">
                    <select name="festival_category_id" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($edition->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>
                    <input name="sections[0][name]" value="{{ __('app.festival_technique') }}" required class="crm-field">
                    <input type="number" step="0.01" name="sections[0][weight]" value="1" required class="crm-field">
                    <input name="sections[0][criteria][0][name]" value="{{ __('app.festival_execution') }}" required class="crm-field">
                    <div class="grid grid-cols-2 gap-2"><input type="number" step="0.01" name="sections[0][criteria][0][max_score]" value="10" required class="crm-field"><input type="number" step="0.01" name="sections[0][criteria][0][weight]" value="1" required class="crm-field"></div>
                    <x-ui.button type="submit">{{ __('app.add') }}</x-ui.button>
                </form>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.score-sheets.prepare', [$account, $edition]) }}" class="mt-3">
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
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.results.publish', [$account, $edition, $category]) }}" class="flex items-center justify-between gap-3 rounded-xl border border-stone-200 p-4">
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
                    <a href="{{ route('dashboard.accounts.festivals.score-sheets.edit', [$account, $edition, $sheet]) }}" class="rounded-xl border border-stone-200 p-4 transition hover:border-brand-300 hover:bg-brand-50/40"><strong>{{ $sheet->entry->performer_name }}</strong><span class="mt-1 block text-sm text-slate-500">{{ $sheet->entry->category->name }} · {{ $sheet->status->value }} · {{ $sheet->total_score }}</span></a>
                @empty
                    <div class="md:col-span-2"><x-ui.empty-state icon="clipboard-check">{{ __('app.festival_score_sheets_empty') }}</x-ui.empty-state></div>
                @endforelse
            </div>
        </section>
    @endif
</x-festivals.staff.workspace>
@endsection
