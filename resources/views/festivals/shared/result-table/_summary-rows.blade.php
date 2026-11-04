@forelse($results['rows'] as $row)
    @php
        $entryNominations = $row['entry']->participants->flatMap->nominations->pluck('name')->unique()->values();
        $sheetsByAssignment = $row['entry']->scoreSheets->keyBy('festival_judge_assignment_id');
    @endphp
    <tr class="bg-white hover:bg-sky-50/30" data-result-summary-entry="{{ $row['entry']->id }}">
        <td class="sticky left-0 z-20 w-16 min-w-16 border-r border-b border-slate-300 bg-white px-2 py-1.5 align-top font-mono text-[10px] font-semibold leading-tight text-slate-600">{{ $row['entry']->code ?: $row['entry']->id }}</td>
        <td class="sticky left-16 z-20 w-40 min-w-40 border-r border-b border-slate-300 bg-white px-2 py-1.5 align-top shadow-[2px_0_0_0_#cbd5e1]"><p class="truncate text-xs font-semibold text-slate-950" title="{{ $row['entry']->entry_name }}">{{ $row['entry']->entry_name }}</p><p class="mt-0.5 truncate text-[10px] text-slate-500" title="{{ $row['entry']->participants->map->displayName()->join(', ') }}">{{ $row['entry']->participants->map->displayName()->join(', ') }}</p></td>
        <td class="w-20 min-w-20 border-r border-b border-slate-300 bg-amber-50 px-2 py-1.5 text-center text-sm font-bold text-slate-950" data-result-summary-total>{{ $row['total'] }}</td>
        @foreach($assignments as $assignment)
            @php($sheet = $sheetsByAssignment->get($assignment->id))
            <td class="border-r border-b border-slate-300 px-2 py-1.5 text-center" data-result-summary-judge="{{ $assignment->id }}">{{ $sheet?->scores->contains(fn ($mark) => $mark->score !== null) ? $sheet->total_score : '—' }}</td>
        @endforeach
        <td class="w-28 min-w-28 border-r border-b border-slate-300 px-2 py-1.5 text-center text-rose-700" data-result-summary-deductions>{{ $row['deduction_total'] }}</td>
        <td class="w-24 min-w-24 border-r border-b border-slate-300 px-2 py-1.5 text-center text-rose-700" data-result-summary-other-penalties>{{ $row['ad_hoc_penalties'] }}</td>
        <td class="w-32 min-w-32 border-r border-b border-slate-300 px-2 py-1.5 text-center"><span class="inline-flex px-1.5 py-0.5 text-[10px] font-semibold {{ $row['ready'] ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ $row['ready'] ? __('app.festival_score_ready') : trans_choice('app.festival_scores_missing', $row['missing'], ['count' => $row['missing']]) }}</span></td>
        <td @class([
            'w-24 min-w-24 border-r border-b border-slate-300 px-2 py-1.5 text-center text-sm font-bold',
            'bg-rose-100 text-rose-900' => $row['podium_tie'],
        ]) @if($row['podium_tie']) data-result-podium-tie @endif>
            {{ $row['rank'] }}{{ $row['tied'] ? '=' : '' }}
            @if($row['podium_tie'])
                <span class="block text-[10px] leading-tight">{{ __('app.festival_results_tie') }}</span>
            @endif
        </td>
        <td class="w-44 min-w-44 border-b border-slate-300 px-2 py-1.5 text-[10px] text-slate-600">{{ $entryNominations->isEmpty() ? '—' : $entryNominations->join(', ') }}</td>
    </tr>
@empty
    <tr><td colspan="{{ 8 + $assignments->count() }}" class="px-5 py-10 text-center text-sm text-slate-500">{{ __('app.festival_results_empty') }}</td></tr>
@endforelse
