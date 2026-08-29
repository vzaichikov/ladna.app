<div
    class="space-y-5"
    data-festival-realtime-results
    data-fragment-url="{{ $fragmentUrl }}"
    data-refresh-seconds="5"
    data-refresh-error="{{ __('app.festival_results_refresh_error') }}"
>
    <section class="rounded-2xl border border-violet-crm-200 bg-linear-to-br from-violet-crm-50 via-white to-rose-50 p-5 shadow-crm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                @if ($category->direction)
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-violet-crm-700">{{ $category->direction->name }}</p>
                @endif
                <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ $category->name }}</h2>
                @if ($results['rubric'])
                    <p class="mt-1 text-sm text-slate-600">{{ $results['rubric']->name }}</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <span @class([
                    'rounded-full px-3 py-1.5 text-xs font-bold',
                    'bg-emerald-100 text-emerald-800' => $results['ready'],
                    'bg-rose-100 text-rose-800' => ! $results['ready'],
                ])>
                    {{ $results['ready']
                        ? __('app.festival_score_ready')
                        : ($results['missing'] > 0
                            ? trans_choice('app.festival_scores_missing', $results['missing'], ['count' => $results['missing']])
                            : __('app.festival_results_not_ready')) }}
                </span>
                <span class="inline-flex items-center gap-2 rounded-full border border-violet-crm-200 bg-white px-3 py-1.5 text-xs font-semibold text-violet-crm-700">
                    <x-ui.icon name="refresh-cw" class="h-3.5 w-3.5" />
                    <span>{{ __('app.festival_results_refresh_in') }}</span>
                    <span data-results-refresh-countdown>5</span>
                </span>
            </div>
        </div>
        <p class="mt-4 text-sm font-semibold text-slate-600">
            {{ __('app.festival_score_progress', ['completed' => $results['completed'], 'required' => $results['required']]) }}
        </p>
    </section>

    @if ($results['issues']->isNotEmpty())
        <div class="space-y-2" data-results-issues>
            @foreach ($results['issues'] as $issue)
                <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">{{ $issue }}</p>
            @endforeach
        </div>
    @endif

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($results['rows'] as $rowIndex => $row)
            <article
                data-result-row
                data-entry-id="{{ $row['entry']->id }}"
                class="grid gap-4 border-b border-stone-200 p-5 last:border-b-0 lg:grid-cols-[5rem_minmax(0,1fr)_minmax(20rem,1.3fr)_9rem] lg:items-center {{ $rowIndex % 2 === 0 ? 'bg-white' : 'bg-violet-crm-50/35' }}"
            >
                <div class="flex items-center gap-2 lg:block">
                    <p class="text-3xl font-bold tabular-nums text-slate-950">#{{ $row['rank'] }}</p>
                    @if ($row['tied'])
                        <span class="mt-1 inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">{{ __('app.festival_results_tie') }}</span>
                    @endif
                </div>
                <div class="min-w-0">
                    <h3 class="truncate text-lg font-semibold text-slate-950">{{ $row['entry']->entry_name }}</h3>
                    <span @class([
                        'mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                        'bg-emerald-100 text-emerald-800' => $row['ready'],
                        'bg-rose-100 text-rose-800' => ! $row['ready'],
                    ])>
                        {{ $row['ready']
                            ? __('app.festival_score_ready')
                            : ($row['missing'] > 0
                                ? trans_choice('app.festival_scores_missing', $row['missing'], ['count' => $row['missing']])
                                : __('app.festival_results_not_ready')) }}
                    </span>
                </div>
                <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                    <div class="rounded-xl bg-emerald-50 p-3">
                        <dt class="text-xs font-semibold text-emerald-700">{{ __('app.festival_awards_total') }}</dt>
                        <dd class="mt-1 font-bold tabular-nums text-emerald-950">{{ \Illuminate\Support\Number::format((float) $row['award_total'], maxPrecision: 4, locale: app()->getLocale()) }}</dd>
                    </div>
                    <div class="rounded-xl bg-rose-50 p-3">
                        <dt class="text-xs font-semibold text-rose-700">{{ __('app.festival_rubric_deductions_total') }}</dt>
                        <dd class="mt-1 font-bold tabular-nums text-rose-950">{{ \Illuminate\Support\Number::format((float) $row['deduction_total'], maxPrecision: 4, locale: app()->getLocale()) }}</dd>
                    </div>
                    <div class="rounded-xl bg-amber-50 p-3">
                        <dt class="text-xs font-semibold text-amber-700">{{ __('app.festival_other_penalties_total') }}</dt>
                        <dd class="mt-1 font-bold tabular-nums text-amber-950">{{ \Illuminate\Support\Number::format((float) $row['ad_hoc_penalties'], maxPrecision: 4, locale: app()->getLocale()) }}</dd>
                    </div>
                </dl>
                <div class="rounded-xl bg-slate-950 p-4 text-right text-white">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-300">{{ __('app.festival_score_total_label') }}</p>
                    <p class="mt-1 text-3xl font-bold tabular-nums" data-result-total>{{ \Illuminate\Support\Number::format((float) $row['total'], maxPrecision: 4, locale: app()->getLocale()) }}</p>
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.festival_results_no_entries')" icon="trophy" class="m-5">
                {{ __('app.festival_results_no_entries_copy') }}
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>

    <p class="hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700" data-results-refresh-error role="status"></p>
</div>
