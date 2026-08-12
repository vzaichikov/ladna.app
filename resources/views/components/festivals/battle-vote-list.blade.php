@props(['account', 'edition', 'assignment', 'matches', 'votes', 'guest' => false])

<div class="space-y-5">
    @forelse ($matches as $match)
        @php($selectedEntryId = (int) $votes->get($match->id))
        <form method="POST" action="{{ $guest ? route('festival.portal.battle-votes.update', [$account->slug, $edition->slug, $match]) : route('dashboard.accounts.festivals.judging.battle-votes.update', [$account, $edition, $match]) }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            @csrf
            @method('PUT')

            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-slate-950">{{ $match->category->name }}</h2>
                <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                    {{ __('app.festival_battle_round', ['round' => $match->round]) }}
                </span>
            </div>

            <fieldset class="mt-4 grid gap-3 sm:grid-cols-2">
                <legend class="sr-only">{{ __('app.festival_battle_choose_winner') }}</legend>
                @foreach ([['id' => $match->entry_a_id, 'name' => $match->entryA->entry_name], ['id' => $match->entry_b_id, 'name' => $match->entryB->entry_name]] as $entry)
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-stone-200 p-4 has-checked:border-brand-500 has-checked:bg-brand-50">
                        <input type="radio" name="selected_entry_id" value="{{ $entry['id'] }}" @checked($selectedEntryId === $entry['id']) required class="h-4 w-4 border-stone-300 text-brand-600 focus:ring-brand-500">
                        <span class="font-semibold text-slate-900">{{ $entry['name'] }}</span>
                    </label>
                @endforeach
            </fieldset>

            <div class="mt-4 flex justify-end">
                <x-ui.button type="submit">{{ $selectedEntryId ? __('app.festival_battle_change_vote') : __('app.festival_battle_submit_vote') }}</x-ui.button>
            </div>
        </form>
    @empty
        <x-ui.empty-state :title="__('app.festival_battle_votes_empty')" icon="check-circle">
            {{ __('app.festival_battle_votes_empty_copy') }}
        </x-ui.empty-state>
    @endforelse
</div>
