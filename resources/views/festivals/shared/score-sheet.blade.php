@extends($guest ? 'layouts.public' : 'layouts.app')

@section('title', __('app.festival_score_sheet').' - '.$sheet->entry->entry_name)

@section('content')
<main class="{{ $guest ? 'min-h-screen bg-canvas px-5 py-8' : '' }}">
    <div @class(['mx-auto space-y-6', 'max-w-6xl' => $guest, 'max-w-4xl' => ! $guest])>
        @if ($guest)
            @include('festivals.portal._nav')
        @endif

        <header>
            <p class="text-sm font-semibold text-brand-700">{{ $edition->title }}</p>
            <h1 class="mt-1 text-4xl font-semibold">{{ $sheet->entry->entry_name }}</h1>
            <p class="mt-2 text-slate-600">{{ $sheet->entry->participants->map->displayName()->join(', ') }}</p>
        </header>

        @if (session('status'))
            <div class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ $guest ? route('festival.portal.judging.update', [$account->slug, $edition->slug, $sheet]) : route('dashboard.accounts.festivals.judging.score-sheets.update', [$account, $edition, $sheet]) }}" class="space-y-5">
            @csrf
            @method('PUT')

            @foreach ($sheet->rubric->sections as $section)
                <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-xl font-semibold">{{ $section->name }}</h2>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $section->contribution->value === 'deduction' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">
                            {{ __('app.festival_rubric_'.$section->contribution->value) }}
                        </span>
                    </div>
                    <div class="mt-4 space-y-4">
                        @foreach ($section->criteria as $criterion)
                            @php($existing = $sheet->scores->firstWhere('festival_rubric_criterion_id', $criterion->id))
                            <div class="grid gap-3 sm:grid-cols-[1fr_8rem]">
                                <label>
                                    <span class="crm-label">{{ $criterion->name }} · {{ __('app.maximum') }} {{ $criterion->max_score }}</span>
                                    <input type="hidden" name="scores[{{ $criterion->id }}][criterion_id]" value="{{ $criterion->id }}">
                                    <input name="scores[{{ $criterion->id }}][comment]" value="{{ old('scores.'.$criterion->id.'.comment', $existing?->comment) }}" placeholder="{{ __('app.comment') }}" class="crm-field">
                                </label>
                                <label>
                                    <span class="crm-label">{{ __('app.festival_score') }}</span>
                                    <input type="number" step="0.01" min="0" max="{{ $criterion->max_score }}" name="scores[{{ $criterion->id }}][score]" value="{{ old('scores.'.$criterion->id.'.score', $existing?->score) }}" required class="crm-field">
                                </label>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <label class="block rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
                <span class="crm-label">{{ __('app.comment') }}</span>
                <textarea name="comments" rows="4" class="crm-field">{{ old('comments', $sheet->comments) }}</textarea>
            </label>

            <div class="flex flex-wrap justify-end gap-3">
                <x-ui.button type="submit" variant="secondary">{{ __('app.save_draft') }}</x-ui.button>
                <x-ui.button type="submit" name="submit" value="1">{{ __('app.festival_submit_scores') }}</x-ui.button>
            </div>
        </form>
    </div>
</main>
@endsection
