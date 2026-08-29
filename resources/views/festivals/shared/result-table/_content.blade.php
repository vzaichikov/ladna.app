@php
    $tableRoute = fn(array $query = []) => $guest
        ? route('festival.portal.judging.results.table', [$account->slug, $edition, $category]).($query ? '?'.http_build_query($query) : '')
        : route('dashboard.accounts.festivals.judging.results.table', [$account, $edition, $category]).($query ? '?'.http_build_query($query) : '');
    $scoreRoute = fn($sheet) => $guest
        ? route('festival.portal.judging.results.table.score-sheets.update', [$account->slug, $edition, $category, $sheet])
        : route('dashboard.accounts.festivals.judging.results.table.score-sheets.update', [$account, $edition, $category, $sheet]);
@endphp

<div class="space-y-3" data-festival-result-table data-save-error="{{ __('app.festival_result_table_save_error') }}">
    @if(session('status'))<div class="border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800">{{ $errors->first() }}</div>@endif

    <div class="flex items-end gap-px overflow-x-auto border-b border-slate-300 bg-slate-100 px-1 pt-1 scrollbar-thin" role="tablist">
        <a href="{{ $tableRoute() }}" aria-current="{{ $activeTab === 'summary' ? 'page' : 'false' }}" class="-mb-px whitespace-nowrap rounded-t-md border px-3 py-1.5 text-xs font-semibold {{ $activeTab === 'summary' ? 'border-slate-300 border-b-white bg-white text-slate-950' : 'border-transparent text-slate-600 hover:border-slate-300 hover:bg-white/70 hover:text-slate-950' }}">{{ __('app.festival_result_table_summary') }}</a>
        @foreach($assignments as $assignment)
            <a href="{{ $tableRoute(['tab' => 'judge-'.$assignment->id]) }}" aria-current="{{ $activeTab === 'judge-'.$assignment->id ? 'page' : 'false' }}" class="-mb-px whitespace-nowrap rounded-t-md border px-3 py-1.5 text-xs font-semibold {{ $activeTab === 'judge-'.$assignment->id ? 'border-slate-300 border-b-white bg-white text-slate-950' : 'border-transparent text-slate-600 hover:border-slate-300 hover:bg-white/70 hover:text-slate-950' }}">{{ $assignment->display_name }}</a>
        @endforeach
        <a href="{{ $tableRoute(['tab' => 'penalties']) }}" aria-current="{{ $activeTab === 'penalties' ? 'page' : 'false' }}" class="-mb-px whitespace-nowrap rounded-t-md border px-3 py-1.5 text-xs font-semibold {{ $activeTab === 'penalties' ? 'border-slate-300 border-b-white bg-white text-slate-950' : 'border-transparent text-slate-600 hover:border-slate-300 hover:bg-white/70 hover:text-slate-950' }}">{{ __('app.festival_result_table_penalties') }}</a>
    </div>

    @unless($editable)<div class="border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800">{{ __('app.festival_result_table_read_only') }}</div>@endunless

    @if($activeTab === 'summary')
        <div class="max-h-[calc(100vh-16rem)] overflow-auto border border-slate-300 bg-white shadow-xs scrollbar-thin" data-result-sheet-scroll>
            <table class="w-full min-w-[52rem] border-separate border-spacing-0 text-left text-xs tabular-nums" data-result-summary-grid>
                <thead class="text-[10px] font-semibold uppercase tracking-wide text-slate-700"><tr><th class="sticky top-0 left-0 z-40 w-16 min-w-16 border-r border-b border-slate-300 bg-sky-100 px-2 py-2">#</th><th class="sticky top-0 left-16 z-40 w-40 min-w-40 border-r border-b border-slate-300 bg-sky-100 px-2 py-2 shadow-[2px_0_0_0_#cbd5e1]">{{ __('app.festival_entry') }}</th><th class="sticky top-0 z-30 w-20 min-w-20 border-r border-b border-slate-300 bg-amber-100 px-2 py-2 text-center">{{ __('app.festival_result_total') }}</th><th class="sticky top-0 z-30 w-20 min-w-20 border-r border-b border-slate-300 bg-sky-100 px-2 py-2 text-center">{{ __('app.festival_result_penalty') }}</th><th class="sticky top-0 z-30 w-32 min-w-32 border-r border-b border-slate-300 bg-sky-100 px-2 py-2 text-center">{{ __('app.status') }}</th><th class="sticky top-0 z-30 w-16 min-w-16 border-r border-b border-slate-300 bg-sky-100 px-2 py-2 text-center">{{ __('app.festival_result_rank') }}</th><th class="sticky top-0 z-30 w-44 min-w-44 border-b border-slate-300 bg-sky-100 px-2 py-2">{{ __('app.festival_nominations') }}</th></tr></thead>
                <tbody data-result-summary-rows>@include('festivals.shared.result-table._summary-rows')</tbody>
            </table>
        </div>
    @elseif($activeAssignment)
        <div class="max-h-[calc(100vh-16rem)] overflow-auto border border-slate-300 bg-white shadow-xs scrollbar-thin" data-result-sheet-scroll>
            <table class="min-w-max border-separate border-spacing-0 text-left text-xs" data-result-judge-grid>
                <thead class="text-[10px] font-semibold uppercase tracking-wide text-slate-700"><tr><th class="sticky top-0 left-0 z-40 w-16 min-w-16 border-r border-b border-slate-300 bg-sky-100 px-2 py-2">#</th><th class="sticky top-0 left-16 z-40 w-40 min-w-40 border-r border-b border-slate-300 bg-sky-100 px-2 py-2 shadow-[2px_0_0_0_#cbd5e1]">{{ __('app.festival_entry') }}</th>@foreach($criteria as $criterion)<th class="sticky top-0 z-30 w-32 min-w-32 border-r border-b border-slate-300 bg-sky-100 px-2 py-1.5 align-bottom" data-result-criterion-header="{{ $criterion->id }}"><span class="block leading-tight normal-case">{{ $criterion->name }}</span><span class="mt-1 block font-normal normal-case text-slate-500">0–{{ $criterion->max_score }}</span></th>@endforeach<th class="sticky top-0 z-30 w-44 min-w-44 border-r border-b border-slate-300 bg-sky-100 px-2 py-2">{{ __('app.festival_score_sheet_comment') }}</th><th class="sticky top-0 z-30 w-20 min-w-20 border-b border-slate-300 bg-amber-100 px-2 py-2 text-center">{{ __('app.festival_result_total') }}</th></tr></thead>
                <tbody>
                    @foreach($results['rows']->sortBy(fn($row) => $row['entry']->id) as $row)
                        @php
                            $sheet = $row['entry']->scoreSheets->firstWhere('festival_judge_assignment_id', $activeAssignment->id);
                        @endphp
                        <tr class="bg-white hover:bg-sky-50/30">
                            <td class="sticky left-0 z-20 w-16 min-w-16 border-r border-b border-slate-300 bg-white px-2 py-1.5 align-top font-mono text-[10px] font-semibold leading-tight text-slate-600">{{ $row['entry']->code ?: $row['entry']->id }}</td>
                            <td class="sticky left-16 z-20 w-40 min-w-40 border-r border-b border-slate-300 bg-white px-2 py-1.5 align-top shadow-[2px_0_0_0_#cbd5e1]"><p class="truncate text-xs font-semibold text-slate-950" title="{{ $row['entry']->entry_name }}">{{ $row['entry']->entry_name }}</p><p class="mt-0.5 truncate text-[10px] text-slate-500" title="{{ $row['entry']->participants->map->displayName()->join(', ') }}">{{ $row['entry']->participants->map->displayName()->join(', ') }}</p></td>
                            @foreach($criteria as $criterion)
                                @php
                                    $criterionScore = $sheet?->scores->firstWhere('festival_rubric_criterion_id', $criterion->id);
                                @endphp
                                <td class="w-32 min-w-32 border-r border-b border-slate-300 p-0 align-top" data-result-criterion-cell="{{ $criterion->id }}">
                                    @if($editable && $sheet)
                                        <form method="POST" action="{{ $scoreRoute($sheet) }}" data-result-table-form class="border-b border-slate-200">@csrf @method('PUT')<input type="hidden" name="scores[0][criterion_id]" value="{{ $criterion->id }}"><input type="number" name="scores[0][score]" value="{{ $criterionScore?->score }}" min="0" max="{{ $criterion->max_score }}" step="0.01" inputmode="decimal" aria-label="{{ $criterion->name }} · {{ __('app.festival_score') }}" class="h-8 w-full bg-transparent px-2 text-center text-sm font-semibold tabular-nums text-slate-950 outline-none focus:bg-emerald-50 focus:ring-2 focus:ring-inset focus:ring-emerald-300" data-result-table-control></form>
                                        <form method="POST" action="{{ $scoreRoute($sheet) }}" data-result-table-form>@csrf @method('PUT')<input type="hidden" name="scores[0][criterion_id]" value="{{ $criterion->id }}"><input name="scores[0][comment]" value="{{ $criterionScore?->comment }}" maxlength="3000" aria-label="{{ $criterion->name }} · {{ __('app.comment') }}" placeholder="{{ __('app.comment') }}" class="h-7 w-full bg-transparent px-2 text-[10px] text-slate-500 outline-none placeholder:text-slate-300 focus:bg-emerald-50 focus:text-slate-800 focus:ring-2 focus:ring-inset focus:ring-emerald-300" data-result-table-control></form>
                                    @else
                                        <span class="flex h-8 items-center justify-center border-b border-slate-200 px-2 text-sm font-semibold tabular-nums text-slate-950">{{ $criterionScore?->score ?? '—' }}</span>
                                        <span class="block min-h-7 truncate px-2 py-1 text-[10px] text-slate-500" title="{{ $criterionScore?->comment }}">{{ $criterionScore?->comment ?: '—' }}</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="w-44 min-w-44 border-r border-b border-slate-300 p-0 align-top">@if($editable && $sheet)<form method="POST" action="{{ $scoreRoute($sheet) }}" data-result-table-form>@csrf @method('PUT')<textarea name="comments" rows="2" maxlength="5000" aria-label="{{ __('app.festival_score_sheet_comment') }}" placeholder="{{ __('app.festival_score_sheet_comment') }}" class="h-[3.75rem] w-full resize-none bg-transparent px-2 py-1.5 text-[10px] leading-tight text-slate-600 outline-none placeholder:text-slate-300 focus:bg-emerald-50 focus:text-slate-900 focus:ring-2 focus:ring-inset focus:ring-emerald-300" data-result-table-control>{{ $sheet->comments }}</textarea></form>@else<span class="block h-[3.75rem] overflow-hidden px-2 py-1.5 text-[10px] leading-tight text-slate-600">{{ $sheet?->comments ?: '—' }}</span>@endif</td>
                            <td class="w-20 min-w-20 border-b border-slate-300 bg-amber-50 px-2 py-2 text-center text-sm font-bold tabular-nums text-slate-950" data-sheet-total="{{ $sheet?->id }}">{{ $sheet?->total_score ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        @php
            $penaltyStoreRoute = $guest ? route('festival.portal.judging.results.table.penalties.store', [$account->slug, $edition, $category]) : route('dashboard.accounts.festivals.judging.results.table.penalties.store', [$account, $edition, $category]);
        @endphp
        <div class="max-h-[calc(100vh-16rem)] overflow-auto border border-slate-300 bg-white shadow-xs scrollbar-thin" data-result-sheet-scroll>
            <table class="min-w-[50rem] border-separate border-spacing-0 text-left text-xs" data-result-penalties-grid>
                <thead class="text-[10px] font-semibold uppercase tracking-wide text-slate-700"><tr><th class="sticky top-0 left-0 z-40 w-16 min-w-16 border-r border-b border-slate-300 bg-sky-100 px-2 py-2">#</th><th class="sticky top-0 left-16 z-40 w-40 min-w-40 border-r border-b border-slate-300 bg-sky-100 px-2 py-2 shadow-[2px_0_0_0_#cbd5e1]">{{ __('app.festival_entry') }}</th><th class="sticky top-0 z-30 w-20 min-w-20 border-r border-b border-slate-300 bg-amber-100 px-2 py-2 text-center">{{ __('app.festival_result_penalty') }}</th><th class="sticky top-0 z-30 border-b border-slate-300 bg-sky-100 px-2 py-2">{{ __('app.festival_result_table_penalties') }}</th></tr></thead>
                <tbody>
                @foreach($results['rows']->sortBy(fn($row) => $row['entry']->id) as $row)
                    <tr class="bg-white hover:bg-sky-50/30">
                        <td class="sticky left-0 z-20 w-16 min-w-16 border-r border-b border-slate-300 bg-white px-2 py-1.5 align-top font-mono text-[10px] font-semibold leading-tight text-slate-600">{{ $row['entry']->code ?: $row['entry']->id }}</td>
                        <td class="sticky left-16 z-20 w-40 min-w-40 border-r border-b border-slate-300 bg-white px-2 py-1.5 align-top shadow-[2px_0_0_0_#cbd5e1]"><p class="truncate text-xs font-semibold text-slate-950" title="{{ $row['entry']->entry_name }}">{{ $row['entry']->entry_name }}</p><p class="mt-0.5 truncate text-[10px] text-slate-500" title="{{ $row['entry']->participants->map->displayName()->join(', ') }}">{{ $row['entry']->participants->map->displayName()->join(', ') }}</p></td>
                        <td class="w-20 min-w-20 border-r border-b border-slate-300 bg-amber-50 px-2 py-2 text-center text-sm font-bold tabular-nums text-rose-700">− {{ $row['ad_hoc_penalties'] }}</td>
                        <td class="border-b border-slate-300 p-0 align-top">
                            <div class="divide-y divide-slate-200">
                        @foreach($row['entry']->penalties as $penalty)
                            @php
                                $updateRoute = $guest ? route('festival.portal.judging.results.table.penalties.update', [$account->slug, $edition, $category, $penalty]) : route('dashboard.accounts.festivals.judging.results.table.penalties.update', [$account, $edition, $category, $penalty]);
                                $deleteRoute = $guest ? route('festival.portal.judging.results.table.penalties.destroy', [$account->slug, $edition, $category, $penalty]) : route('dashboard.accounts.festivals.judging.results.table.penalties.destroy', [$account, $edition, $category, $penalty]);
                            @endphp
                            @if($editable)
                                <form method="POST" action="{{ $updateRoute }}" data-async-form data-async-success="reload" class="grid min-w-[32rem] grid-cols-[5rem_minmax(12rem,1fr)_auto] items-stretch gap-px bg-slate-200">@csrf @method('PUT')<input type="hidden" name="festival_entry_id" value="{{ $row['entry']->id }}"><input type="number" name="points" value="{{ $penalty->points }}" min="0.01" step="0.01" inputmode="decimal" required aria-label="{{ __('app.festival_result_penalty') }}" class="h-8 bg-white px-2 text-center font-semibold tabular-nums outline-none focus:bg-emerald-50 focus:ring-2 focus:ring-inset focus:ring-emerald-300"><input name="reason" value="{{ $penalty->reason }}" maxlength="1000" required aria-label="{{ __('app.festival_penalty_reason') }}" class="h-8 bg-white px-2 outline-none focus:bg-emerald-50 focus:ring-2 focus:ring-inset focus:ring-emerald-300"><div class="flex items-center bg-white px-1"><button type="submit" class="p-1.5 text-emerald-700 hover:bg-emerald-50" aria-label="{{ __('app.save') }}"><x-ui.icon name="save" class="h-4 w-4" /></button><button type="submit" form="penalty-delete-{{ $penalty->id }}" class="p-1.5 text-rose-700 hover:bg-rose-50" aria-label="{{ __('app.delete') }}"><x-ui.icon name="trash" class="h-4 w-4" /></button></div></form>
                                <form id="penalty-delete-{{ $penalty->id }}" method="POST" action="{{ $deleteRoute }}" data-async-form data-async-success="reload">@csrf @method('DELETE')</form>
                            @else<div class="grid min-w-[32rem] grid-cols-[5rem_minmax(12rem,1fr)] gap-px bg-slate-200"><strong class="bg-white px-2 py-1.5 text-center tabular-nums">{{ $penalty->points }}</strong><span class="bg-white px-2 py-1.5">{{ $penalty->reason }}</span></div>@endif
                        @endforeach
                        @if($editable)<form method="POST" action="{{ $penaltyStoreRoute }}" data-async-form data-async-success="reload" class="grid min-w-[32rem] grid-cols-[5rem_minmax(12rem,1fr)_auto] items-stretch gap-px bg-slate-200">@csrf<input type="hidden" name="festival_entry_id" value="{{ $row['entry']->id }}"><input type="number" name="points" min="0.01" step="0.01" inputmode="decimal" required aria-label="{{ __('app.festival_result_penalty') }}" class="h-8 bg-white px-2 text-center font-semibold tabular-nums outline-none placeholder:text-slate-300 focus:bg-emerald-50 focus:ring-2 focus:ring-inset focus:ring-emerald-300" placeholder="0.00"><input name="reason" maxlength="1000" required aria-label="{{ __('app.festival_penalty_reason') }}" class="h-8 bg-white px-2 outline-none placeholder:text-slate-300 focus:bg-emerald-50 focus:ring-2 focus:ring-inset focus:ring-emerald-300" placeholder="{{ __('app.festival_penalty_reason') }}"><div class="flex items-center bg-white px-1"><button type="submit" class="p-1.5 text-violet-crm-700 hover:bg-violet-crm-50" aria-label="{{ __('app.add') }}"><x-ui.icon name="plus" class="h-4 w-4" /></button></div></form>@endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="hidden border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700" data-result-table-error role="status"></p>
</div>
