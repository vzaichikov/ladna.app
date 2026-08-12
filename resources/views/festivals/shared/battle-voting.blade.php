@extends($guest ? 'layouts.public' : 'layouts.app')

@section('title', __('app.festival_battle_voting').' - '.$edition->title)

@section('content')
@if ($guest)
    <main class="min-h-screen bg-canvas px-5 py-8">
        <div class="mx-auto max-w-4xl space-y-6">
            @include('festivals.portal._nav')
            <header>
                <p class="text-sm font-semibold text-brand-700">{{ $edition->title }}</p>
                <h1 class="mt-1 text-4xl font-semibold">{{ __('app.festival_battle_voting') }}</h1>
                <p class="mt-2 text-slate-600">{{ $assignment->display_name }}</p>
            </header>
            <x-festivals.battle-vote-list :$account :$edition :$assignment :$matches :$votes :$guest />
        </div>
    </main>
@else
    <x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
        <x-ui.page-header :title="__('app.festival_battle_voting')" :copy="__('app.festival_battle_voting_copy')" />
        <p class="text-sm text-slate-600">{{ __('app.festival_score_sheets_for_judge', ['judge' => $assignment->display_name]) }}</p>
        <x-festivals.battle-vote-list :$account :$edition :$assignment :$matches :$votes :$guest />
    </x-festivals.staff.workspace>
@endif
@endsection
