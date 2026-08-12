@extends('layouts.app')

@section('title', __('app.festival_results_preview').' - '.$category->name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_results_preview').' · '.$category->name" :copy="__('app.festival_results_preview_copy')" />

    <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.results.publish', [$account, $edition, $category]) }}" class="space-y-6">
        @csrf

        <x-ui.panel padding="none" class="overflow-hidden">
            @foreach ($preview['rows'] as $index => $row)
                <div class="crm-row lg:grid-cols-[5rem_minmax(0,1fr)_repeat(4,9rem)] lg:items-center">
                    <p class="text-2xl font-semibold text-slate-950">#{{ $index + 1 }}</p>
                    <div class="min-w-0">
                        <h2 class="truncate font-semibold text-slate-950">{{ $row['entry']->entry_name }}</h2>
                    </div>
                    <p class="text-sm text-slate-600">{{ __('app.festival_awards_total') }}<br><strong>{{ $row['award_total'] }}</strong></p>
                    <p class="text-sm text-slate-600">{{ __('app.festival_rubric_deductions_total') }}<br><strong>{{ $row['deduction_total'] }}</strong></p>
                    <p class="text-sm text-slate-600">{{ __('app.festival_other_penalties_total') }}<br><strong>{{ $row['ad_hoc_penalties'] }}</strong></p>
                    <p class="text-sm text-slate-950">{{ __('app.festival_score_total', ['score' => $row['total']]) }}</p>
                </div>
            @endforeach
        </x-ui.panel>

        @foreach ($preview['ties'] as $tieIndex => $tie)
            <x-ui.panel>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_tie_break_title', ['score' => $tie['total']]) }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_tie_break_copy') }}</p>
                <input type="hidden" name="tie_breaks[{{ $tieIndex }}][total]" value="{{ $tie['total'] }}">

                <div class="mt-4 space-y-3">
                    @foreach ($tie['rows'] as $row)
                        <label class="grid gap-2 rounded-xl border border-stone-200 p-3 sm:grid-cols-[minmax(0,1fr)_8rem] sm:items-center">
                            <span class="font-medium text-slate-950">{{ $row['entry']->entry_name }}</span>
                            <span>
                                <span class="crm-label">{{ __('app.festival_jury_order') }}</span>
                                <input type="number" min="1" max="{{ $tie['rows']->count() }}" name="tie_breaks[{{ $tieIndex }}][orders][{{ $row['entry']->id }}]" value="{{ old('tie_breaks.'.$tieIndex.'.orders.'.$row['entry']->id) }}" required class="crm-field">
                            </span>
                        </label>
                    @endforeach
                </div>

                <label class="mt-4 block">
                    <span class="crm-label">{{ __('app.festival_jury_reason') }}</span>
                    <textarea name="tie_breaks[{{ $tieIndex }}][reason]" rows="3" maxlength="2000" required class="crm-field">{{ old('tie_breaks.'.$tieIndex.'.reason') }}</textarea>
                </label>
            </x-ui.panel>
        @endforeach

        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $errors->first() }}</div>
        @endif

        <div class="flex flex-wrap justify-end gap-3">
            <x-ui.button :href="route('dashboard.accounts.festivals.judging.results.index', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
            <x-ui.button type="submit">{{ __('app.publish') }}</x-ui.button>
        </div>
    </form>
</x-festivals.staff.workspace>
@endsection
