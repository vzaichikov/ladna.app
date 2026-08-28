@php
    $performers = $participants->filter(
        fn ($participant) => ($participant->member_type ?? \App\Enums\FestivalTeamMemberType::Performer) === \App\Enums\FestivalTeamMemberType::Performer,
    );
    $helpers = $participants->filter(
        fn ($participant) => $participant->member_type === \App\Enums\FestivalTeamMemberType::Helper,
    );
@endphp

<div class="space-y-8" data-festival-team-list aria-live="polite">
    @foreach([
        ['type' => 'performers', 'members' => $performers, 'icon' => 'sparkles'],
        ['type' => 'helpers', 'members' => $helpers, 'icon' => 'hand-helping'],
    ] as $section)
        <section aria-labelledby="festival-team-{{ $section['type'] }}-title" data-festival-team-group="{{ $section['type'] }}">
            <div class="mb-3 flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-100 text-brand-700" aria-hidden="true">
                    <x-ui.icon :name="$section['icon']" class="h-4 w-4" />
                </span>
                <h2 id="festival-team-{{ $section['type'] }}-title" class="text-xl font-semibold text-slate-950">
                    {{ __('app.festival_team_'.$section['type']) }}
                </h2>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $section['members']->count() }}</span>
            </div>

            @if($section['members']->isEmpty())
                <div class="rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-8 text-center text-sm text-slate-500">
                    {{ __('app.festival_team_'.$section['type'].'_empty') }}
                </div>
            @else
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach($section['members'] as $participant)
                        @include('festivals.portal.team._member-card', ['participant' => $participant, 'account' => $account])
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach
</div>
