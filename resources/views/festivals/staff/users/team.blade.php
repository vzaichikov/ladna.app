@extends('layouts.app')

@section('title', __('app.festival_participant_edit_tab_team').' - '.$portalUser->displayName().' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_participant_edit_tab_team')" :copy="$portalUser->displayName()">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.users.participants.create', [$account, $edition, $portalUser])">
                <x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_participant') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('festivals.staff.users._detail-nav', ['activeDetailPage' => 'team'])

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">{{ session('status') }}</div>
    @endif

    @foreach ([
        ['key' => 'performers', 'title' => __('app.festival_team_performers'), 'members' => $performers],
        ['key' => 'helpers', 'title' => __('app.festival_team_helpers'), 'members' => $helpers],
    ] as $group)
        <section class="space-y-3" data-festival-team-group="{{ $group['key'] }}">
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-semibold text-slate-950">{{ $group['title'] }}</h2>
                <span class="inline-flex min-w-7 items-center justify-center rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ $group['members']->count() }}</span>
            </div>

            <x-ui.panel padding="none" class="overflow-hidden">
                @forelse ($group['members'] as $participant)
                    @php
                        $hasPhoto = $participant->is_profile_owner ? filled($portalUser->avatar_path) : filled($participant->photo_path);
                        $photoUrl = $participant->is_profile_owner
                            ? route('dashboard.accounts.festivals.users.photo', [$account, $edition, $portalUser])
                            : route('dashboard.accounts.festivals.users.participants.photo', [$account, $edition, $portalUser, $participant]);
                        $usageCount = $group['key'] === 'performers' ? $participant->entries_count : $participant->helper_requirements_count;
                    @endphp
                    <div class="crm-row lg:grid-cols-[minmax(0,1fr)_170px_auto] lg:items-center">
                        <div class="flex min-w-0 items-center gap-3">
                            @if ($hasPhoto)
                                <img src="{{ $photoUrl }}" alt="" class="h-12 w-12 shrink-0 rounded-full object-cover">
                            @else
                                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-bold text-brand-800" aria-hidden="true">
                                    {{ str($participant->first_name)->substr(0, 1)->upper() }}{{ str($participant->last_name)->substr(0, 1)->upper() }}
                                </span>
                            @endif
                            <div class="min-w-0">
                                <p class="truncate font-semibold text-slate-950">{{ $participant->displayName() }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ $participant->date_of_birth->format('d.m.Y') }}@if($participant->is_profile_owner) · {{ __('app.festival_participant_profile') }}@endif</p>
                            </div>
                        </div>
                        <div class="text-sm text-slate-500">
                            {{ trans_choice($group['key'] === 'performers' ? 'app.festival_entries_usage_count' : 'app.festival_helper_usage_count', $usageCount, ['count' => $usageCount]) }}
                            @if ($participant->archived_at)<span class="mt-1 block">{{ __('app.archived') }}</span>@endif
                        </div>
                        <div class="flex justify-end gap-2">
                            @unless ($participant->is_profile_owner)
                                <x-ui.action-button :href="route('dashboard.accounts.festivals.users.participants.edit', [$account, $edition, $portalUser, $participant])" :label="__('app.edit')" />
                                @unless ($participant->archived_at)
                                    <x-ui.action-button :href="route('dashboard.accounts.festivals.users.participants.archive', [$account, $edition, $portalUser, $participant])" icon="archive" :label="__('app.archive')" />
                                @endunless
                            @endunless
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state :title="__('app.festival_participants_empty')" icon="users" class="m-5" />
                @endforelse
            </x-ui.panel>
        </section>
    @endforeach
</x-festivals.staff.workspace>
@endsection
