@extends('layouts.festival-portal')

@section('title', __('app.festival_portal_my_team').' - '.$account->name)

@php
    $failedTeamForm = old('team_form_mode');
    $addModalOpen = $failedTeamForm === 'add'
        || request()->query->has('add');
    $editModalOpen = ($failedTeamForm === 'edit' || request()->filled('edit')) && $editParticipant !== null;
    $editMemberTypeLocked = $editParticipant
        && (((int) ($editParticipant->entries_count ?? 0)) > 0 || ((int) ($editParticipant->helper_requirements_count ?? 0)) > 0);
@endphp

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8" data-festival-team-page>
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')

        <header class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-3xl font-semibold text-slate-950 sm:text-4xl">{{ __('app.festival_portal_my_team') }}</h1>
                <p class="mt-2 max-w-3xl text-slate-600">{{ __('app.festival_portal_team_copy') }}</p>
            </div>
            <x-ui.button :href="route('festival.portal.participants.index', ['accountSlug' => $account->slug, 'add' => 'new'])" class="shrink-0" data-festival-team-add-open>
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_team_add_member') }}
            </x-ui.button>
        </header>

        <div class="mt-6 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
            <x-ui.icon name="triangle-alert" class="mt-0.5 h-5 w-5 shrink-0" />
            <div>
                <p class="font-semibold">{{ __('app.festival_team_member_type_warning_title') }}</p>
                <p class="mt-1">{{ __('app.festival_team_member_type_warning_copy') }}</p>
            </div>
        </div>

        <div
            class="mt-5 {{ session('status') ? '' : 'hidden' }} rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 shadow-xs"
            role="status"
            aria-live="polite"
            data-async-status
            data-error-message="{{ __('app.async_request_failed') }}"
            data-validation-message="{{ __('app.async_validation_failed') }}"
        >{{ session('status') }}</div>

        <div class="mt-8">
            @include('festivals.portal.team._list', ['account' => $account, 'participants' => $participants])
        </div>
    </div>
</main>

@include('festivals.portal.team._member-modal', [
    'account' => $account,
    'modalId' => 'festival-team-add-modal',
    'mode' => 'add',
    'defaultMemberType' => $failedTeamForm === 'add' ? old('member_type') : $addMemberType,
    'fragmentContext' => 'team',
    'open' => $addModalOpen,
    'showErrors' => $failedTeamForm === 'add',
])

@include('festivals.portal.team._member-modal', [
    'account' => $account,
    'modalId' => 'festival-team-edit-modal',
    'mode' => 'edit',
    'participant' => $editParticipant,
    'defaultMemberType' => $editParticipant?->member_type ?? \App\Enums\FestivalTeamMemberType::Performer,
    'fragmentContext' => 'team',
    'open' => $editModalOpen,
    'showErrors' => $editModalOpen,
    'memberTypeLocked' => $editMemberTypeLocked,
])
@endsection
