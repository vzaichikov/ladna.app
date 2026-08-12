@extends('layouts.app')

@section('title', __('app.festival_battles').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_battles')" :copy="__('app.festival_battles_page_copy')" />

    <div class="space-y-6">
        @forelse ($categories as $category)
            @php
                $categoryMatches = $matchesByCategory->get($category->id, collect());
                $judgeCount = (int) $judgeCounts->get($category->id, 0);
            @endphp

            <x-ui.panel>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-slate-950">{{ $category->name }}</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ __('app.festival_battle_category_summary', ['minimum' => $category->minimum_entries_to_run, 'judges' => $judgeCount]) }}
                        </p>
                    </div>

                    <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.battles.generate', [$account, $edition, $category]) }}">
                        @csrf
                        @if ($categoryMatches->isNotEmpty())
                            <input type="hidden" name="regenerate" value="1">
                        @endif
                        <x-ui.button type="submit" variant="secondary">
                            <x-ui.icon name="shuffle" class="h-4 w-4" />
                            {{ $categoryMatches->isEmpty() ? __('app.festival_battle_generate') : __('app.festival_battle_regenerate') }}
                        </x-ui.button>
                    </form>
                </div>

                @if ($categoryMatches->isEmpty())
                    <x-ui.empty-state :title="__('app.festival_battle_bracket_empty')" icon="git-branch" class="mt-5">
                        {{ __('app.festival_battle_bracket_empty_copy') }}
                    </x-ui.empty-state>
                @else
                    <div class="mt-6 overflow-x-auto pb-2">
                        <div class="flex min-w-max items-start gap-5">
                            @foreach ($categoryMatches->groupBy('round') as $round => $roundMatches)
                                <section class="w-80 shrink-0 space-y-4" aria-labelledby="battle-round-{{ $category->id }}-{{ $round }}">
                                    <h3 id="battle-round-{{ $category->id }}-{{ $round }}" class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                        {{ __('app.festival_battle_round', ['round' => $round]) }}
                                    </h3>

                                    @foreach ($roundMatches as $match)
                                        <article class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">
                                            <div class="grid gap-2 text-sm">
                                                <div class="rounded-xl px-3 py-2 {{ $match->winner_entry_id === $match->entry_a_id ? 'bg-emerald-50 text-emerald-900' : 'bg-stone-50 text-slate-800' }}">
                                                    {{ $match->entryA?->entry_name ?? __('app.festival_battle_waiting') }}
                                                </div>
                                                <div class="rounded-xl px-3 py-2 {{ $match->winner_entry_id === $match->entry_b_id ? 'bg-emerald-50 text-emerald-900' : 'bg-stone-50 text-slate-800' }}">
                                                    {{ $match->entryB?->entry_name ?? __('app.festival_battle_waiting') }}
                                                </div>
                                            </div>

                                            <p class="mt-3 text-xs text-slate-500">
                                                {{ __('app.festival_battle_vote_progress', ['submitted' => $match->votes_count, 'total' => $judgeCount]) }}
                                            </p>

                                            @if ($match->winner)
                                                <p class="mt-2 text-sm font-semibold text-emerald-700">
                                                    {{ __('app.festival_battle_winner', ['entry' => $match->winner->entry_name]) }}
                                                </p>
                                                @if ($match->combined_percentage_a !== null)
                                                    <p class="mt-1 text-xs text-slate-500">{{ $match->combined_percentage_a }}% · {{ $match->combined_percentage_b }}%</p>
                                                @endif
                                            @elseif ($match->status->value === 'ready')
                                                <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.battles.finalize', [$account, $edition, $match]) }}" class="mt-4 space-y-3">
                                                    @csrf
                                                    <div class="grid grid-cols-2 gap-3">
                                                        <label>
                                                            <span class="crm-label">{{ __('app.festival_battle_audience_a') }}</span>
                                                            <input type="number" min="0" name="audience_votes_a" value="{{ old('audience_votes_a', 0) }}" required class="crm-field">
                                                        </label>
                                                        <label>
                                                            <span class="crm-label">{{ __('app.festival_battle_audience_b') }}</span>
                                                            <input type="number" min="0" name="audience_votes_b" value="{{ old('audience_votes_b', 0) }}" required class="crm-field">
                                                        </label>
                                                    </div>
                                                    <label>
                                                        <span class="crm-label">{{ __('app.festival_battle_tie_winner') }}</span>
                                                        <select name="tie_winner_entry_id" class="crm-field">
                                                            <option value="">{{ __('app.festival_battle_tie_not_expected') }}</option>
                                                            <option value="{{ $match->entry_a_id }}">{{ $match->entryA->entry_name }}</option>
                                                            <option value="{{ $match->entry_b_id }}">{{ $match->entryB->entry_name }}</option>
                                                        </select>
                                                    </label>
                                                    <label>
                                                        <span class="crm-label">{{ __('app.festival_battle_tie_reason') }}</span>
                                                        <textarea name="tie_break_reason" rows="2" class="crm-field">{{ old('tie_break_reason') }}</textarea>
                                                    </label>
                                                    <x-ui.button type="submit" class="w-full justify-center">{{ __('app.festival_battle_finalize') }}</x-ui.button>
                                                </form>
                                            @endif
                                        </article>
                                    @endforeach
                                </section>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.panel>
        @empty
            <x-ui.empty-state :title="__('app.festival_battle_categories_empty')" icon="git-branch">
                {{ __('app.festival_battle_categories_empty_copy') }}
            </x-ui.empty-state>
        @endforelse
    </div>
</x-festivals.staff.workspace>
@endsection
