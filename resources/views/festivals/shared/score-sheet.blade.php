@extends($guest ? 'layouts.public' : 'layouts.app')

@section('title', __('app.festival_score_sheet').' - '.$sheet->entry->entry_name)

@section('content')
<main class="{{ $guest ? 'min-h-screen bg-canvas px-5 py-8' : '' }}">
    <div @class(['mx-auto space-y-6', 'max-w-6xl' => $guest, 'max-w-4xl' => ! $guest])>
        @if ($guest)
            @include('festivals.portal._nav')
        @endif

        <header class="overflow-hidden rounded-2xl border border-violet-crm-100 bg-linear-to-br from-white via-violet-crm-50/50 to-rose-50 shadow-crm">
            <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-start">
                @php($photoParticipants = $sheet->entry->participants->filter(fn ($participant) => filled($participant->resolvedPhotoPath()))->values())
                @if ($photoParticipants->isNotEmpty())
                    <div class="flex shrink-0 -space-x-4">
                        @foreach ($photoParticipants->take(3) as $participant)
                            <img
                                src="{{ $guest ? route('festival.portal.judging.participants.photo', [$account->slug, $sheet, $participant]) : route('dashboard.accounts.festivals.judging.score-sheets.participants.photo', [$account, $edition, $sheet, $participant]) }}"
                                alt="{{ $participant->displayName() }}"
                                class="h-20 w-20 rounded-2xl border-4 border-white object-cover shadow-md"
                            >
                        @endforeach
                    </div>
                @endif
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-brand-700">{{ $edition->title }}</p>
                    <h1 class="mt-1 text-4xl font-semibold">{{ $sheet->entry->entry_name }}</h1>
                    <p class="mt-2 text-slate-600">{{ $sheet->entry->participants->map->displayName()->join(', ') }}</p>
                </div>
            </div>
            <dl class="grid border-t border-violet-crm-100 bg-white/75 sm:grid-cols-2">
                <div class="border-b border-violet-crm-100 p-5 sm:border-r sm:border-b-0">
                    <dt class="text-xs font-bold uppercase tracking-[0.14em] text-violet-crm-700">{{ __('app.festival_act_title') }}</dt>
                    <dd class="mt-2 text-lg font-semibold text-slate-950" data-score-act-title>{{ $sheet->entry->act_title ?: __('app.not_set') }}</dd>
                </div>
                <div class="p-5">
                    <dt class="text-xs font-bold uppercase tracking-[0.14em] text-violet-crm-700">{{ __('app.festival_act_description') }}</dt>
                    <dd class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700" data-score-act-description>{{ $sheet->entry->act_description ?: __('app.not_set') }}</dd>
                </div>
            </dl>
        </header>

        @if (session('status'))
            <div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $errors->first() }}</div>
        @endif

        <form
            method="POST"
            action="{{ $guest ? route('festival.portal.judging.update', [$account->slug, $sheet]) : route('dashboard.accounts.festivals.judging.score-sheets.update', [$account, $edition, $sheet]) }}"
            class="space-y-5"
            data-festival-score-sheet
            data-save-error="{{ __('app.festival_score_save_error') }}"
            data-saving-label="{{ __('app.festival_score_saving') }}"
            data-saved-label="{{ __('app.festival_score_saved') }}"
            data-changed-label="{{ __('app.festival_score_changed') }}"
            data-ready-label="{{ __('app.festival_score_ready') }}"
            data-missing-one="{{ trans_choice('app.festival_scores_missing', 1, ['count' => 1]) }}"
            data-missing-many="{{ trans_choice('app.festival_scores_missing', 2, ['count' => '__missing__']) }}"
            data-progress-template="{{ __('app.festival_score_progress', ['completed' => '__completed__', 'required' => '__required__']) }}"
        >
            @csrf
            @method('PUT')

            <section class="sticky top-3 z-20 rounded-2xl border border-slate-200 bg-slate-950/95 p-4 text-white shadow-lg transition-[border-color,box-shadow] duration-300 backdrop-blur" data-score-save-feedback>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-300">{{ __('app.festival_score_total_label') }}</p>
                        <p class="mt-1 text-3xl font-bold tabular-nums" data-score-total>{{ \Illuminate\Support\Number::format((float) $sheet->total_score, maxPrecision: 4, locale: app()->getLocale()) }}</p>
                    </div>
                    <div class="text-right">
                        <span data-score-readiness @class([
                            'inline-flex rounded-full px-3 py-1.5 text-sm font-bold',
                            'bg-emerald-400 text-emerald-950' => $scoreProgress['ready'],
                            'bg-rose-400 text-rose-950' => ! $scoreProgress['ready'],
                        ])>
                            {{ $scoreProgress['ready'] ? __('app.festival_score_ready') : trans_choice('app.festival_scores_missing', $scoreProgress['missing'], ['count' => $scoreProgress['missing']]) }}
                        </span>
                        <p class="mt-2 text-xs font-semibold text-slate-300" data-score-progress>{{ __('app.festival_score_progress', ['completed' => $scoreProgress['completed'], 'required' => $scoreProgress['required']]) }}</p>
                    </div>
                </div>
            </section>

            @foreach ($sheet->rubric->sections as $section)
                <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-xl font-semibold">{{ $section->name }}</h2>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $section->contribution->value === 'deduction' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">
                            {{ __('app.festival_rubric_'.$section->contribution->value) }}
                        </span>
                    </div>
                    <div class="mt-4 space-y-4">
                        @foreach ($section->criteria as $criterionIndex => $criterion)
                            @php($existing = $sheet->scores->firstWhere('festival_rubric_criterion_id', $criterion->id))
                            <div class="grid gap-3 rounded-xl border p-4 sm:grid-cols-[minmax(0,1fr)_13rem] {{ $criterionIndex % 2 === 0 ? 'border-sky-100 bg-sky-50/60' : 'border-violet-crm-100 bg-violet-crm-50/50' }}" data-score-criterion-row>
                                <div class="min-w-0 rounded-lg transition-shadow duration-300" data-score-autosave-field>
                                    <label class="block">
                                        <span class="crm-label">{{ $criterion->name }} · {{ __('app.maximum') }} {{ $criterion->max_score }}</span>
                                        <input type="hidden" name="scores[{{ $criterion->id }}][criterion_id]" value="{{ $criterion->id }}">
                                        <input name="scores[{{ $criterion->id }}][comment]" value="{{ old('scores.'.$criterion->id.'.comment', $existing?->comment) }}" placeholder="{{ __('app.comment') }}" class="crm-field transition-[border-color,box-shadow,background-color] duration-300" data-score-comment data-score-autosave-control>
                                    </label>
                                    <p class="mt-1.5 hidden text-xs font-semibold text-slate-400" data-score-field-status></p>
                                </div>
                                <div class="min-w-0 rounded-lg transition-shadow duration-300" data-score-autosave-field>
                                    <span class="crm-label">{{ __('app.festival_score') }}</span>
                                    <div class="grid grid-cols-[3rem_minmax(0,1fr)_3rem] overflow-hidden rounded-lg border border-stone-200 bg-white shadow-xs transition-[border-color,box-shadow,background-color] duration-300 focus-within:border-emerald-300 focus-within:ring-2 focus-within:ring-emerald-100" data-score-stepper data-score-autosave-control>
                                        <button
                                            type="button"
                                            class="inline-flex min-h-11 w-full items-center justify-center bg-slate-50 text-xl font-bold text-slate-700 transition hover:bg-slate-100 hover:text-slate-950 focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                            data-score-adjust="-1"
                                            aria-label="{{ __('app.festival_score_decrease', ['criterion' => $criterion->name]) }}"
                                        >−</button>
                                        <input
                                            type="number"
                                            step="0.5"
                                            min="0"
                                            max="{{ $criterion->max_score }}"
                                            name="scores[{{ $criterion->id }}][score]"
                                            value="{{ old('scores.'.$criterion->id.'.score', $existing?->score) }}"
                                            class="min-h-11 min-w-0 w-full border-x border-stone-200 bg-white px-1 text-center text-lg font-bold tabular-nums text-slate-950 outline-none [appearance:textfield] focus:bg-emerald-50/40 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                            data-score-input
                                            data-criterion-weight="{{ $criterion->weight }}"
                                            data-section-weight="{{ $section->weight }}"
                                            data-contribution="{{ $section->contribution->value }}"
                                            aria-label="{{ $criterion->name }} · {{ __('app.festival_score') }}"
                                        >
                                        <button
                                            type="button"
                                            class="inline-flex min-h-11 w-full items-center justify-center bg-slate-50 text-xl font-bold text-slate-700 transition hover:bg-slate-100 hover:text-slate-950 focus-visible:z-10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                            data-score-adjust="1"
                                            aria-label="{{ __('app.festival_score_increase', ['criterion' => $criterion->name]) }}"
                                        >+</button>
                                    </div>
                                    <p class="mt-1.5 hidden text-xs font-semibold text-slate-400" data-score-field-status></p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm transition-shadow duration-300" data-score-autosave-field>
                <label class="block">
                    <span class="crm-label">{{ __('app.comment') }}</span>
                    <textarea name="comments" rows="4" class="crm-field transition-[border-color,box-shadow,background-color] duration-300" data-score-comment data-score-autosave-control>{{ old('comments', $sheet->comments) }}</textarea>
                </label>
                <p class="mt-1.5 hidden text-xs font-semibold text-slate-400" data-score-field-status></p>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-stone-200 bg-white p-4 shadow-crm">
                <p class="text-sm font-semibold text-slate-500" data-score-save-status aria-live="polite">{{ __('app.festival_score_autosave_copy') }}</p>
                <x-ui.button type="submit" data-score-save-button>
                    <x-ui.icon name="save" class="h-4 w-4" />
                    {{ __('app.save') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</main>
@endsection
