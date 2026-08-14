@php
    $isJudge = $portalUser->role === \App\Enums\FestivalPortalRole::Judge;
    $isGuest = $portalUser->role === \App\Enums\FestivalPortalRole::Guest;
    $isRegistrant = $portalUser->role === \App\Enums\FestivalPortalRole::Registrant;
    $directoryTab = $isJudge ? 'judges' : ($isGuest ? 'guests' : 'participants');
@endphp

@extends('layouts.app')

@section('title', ($portalUser->exists ? __('app.festival_edit_user') : __('app.festival_add_user')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header
        :title="$portalUser->exists ? __('app.festival_edit_user') : ($isJudge ? __('app.festival_add_judge_profile') : ($isGuest ? __('app.festival_add_guest') : __('app.festival_add_registrant')))"
        :copy="__('app.festival_user_form_copy')"
    />

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">{{ session('status') }}</div>
    @endif

    @if ($portalUser->exists && $isRegistrant)
        <nav class="flex gap-1 overflow-x-auto rounded-2xl bg-slate-100 p-1" aria-label="{{ __('app.festival_participant_edit_tabs') }}">
            @foreach (['profile', 'notifications'] as $participantTab)
                <a href="{{ route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser, 'tab' => $participantTab]) }}" class="whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-semibold {{ ($pageTab ?? 'profile') === $participantTab ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-950' }}" @if(($pageTab ?? 'profile') === $participantTab) aria-current="page" @endif>{{ __('app.festival_participant_edit_tab_'.$participantTab) }}</a>
            @endforeach
        </nav>
    @endif

    @if (($pageTab ?? 'profile') === 'profile')
    <form method="POST" action="{{ $portalUser->exists ? route('dashboard.accounts.festivals.users.update', [$account, $edition, $portalUser]) : route('dashboard.accounts.festivals.users.store', [$account, $edition, $portalUser->role->value]) }}" class="max-w-4xl space-y-6">
        @csrf
        @if ($portalUser->exists) @method('PUT') @endif
        @if (($returnTo ?? null) === 'ticket-issuance')<input type="hidden" name="return_to" value="ticket-issuance">@endif

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_profile_personal_details') }}</h2>

            <div class="mt-5 rounded-xl border border-stone-200 bg-slate-50 p-4">
                <p class="crm-label">{{ __('app.role') }}</p>
                <p class="mt-1 font-semibold text-slate-950">{{ $isJudge ? __('app.festival_role_judge') : ($isGuest ? __('app.festival_role_guest') : __('app.festival_role_registrant')) }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.festival_role_immutable_copy') }}</p>
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <label><span class="crm-label">{{ __('app.first_name') }}</span><input name="first_name" value="{{ old('first_name', $portalUser->first_name) }}" required class="crm-field">@error('first_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.last_name') }}</span><input name="last_name" value="{{ old('last_name', $portalUser->last_name) }}" required class="crm-field">@error('last_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.patronymic') }}</span><input name="patronymic" value="{{ old('patronymic', $portalUser->patronymic) }}" class="crm-field">@error('patronymic')<span class="crm-help">{{ $message }}</span>@enderror</label>
                @unless ($isGuest)<label><span class="crm-label">{{ __('app.festival_stage_name') }}</span><input name="stage_name" value="{{ old('stage_name', $portalUser->stage_name) }}" class="crm-field">@error('stage_name')<span class="crm-help">{{ $message }}</span>@enderror</label>@endunless

                @if ($isRegistrant)
                    <label><span class="crm-label">{{ __('app.festival_profile_type') }}</span><select name="registrant_type" required class="crm-field" data-festival-registrant-type>@foreach (\App\Enums\FestivalRegistrantType::selectableCases($portalUser->registrant_type) as $type)<option value="{{ $type->value }}" @selected(old('registrant_type', $portalUser->registrant_type?->value) === $type->value)>{{ __('app.festival_registrant_'.$type->value) }}</option>@endforeach</select>@error('registrant_type')<span class="crm-help">{{ $message }}</span>@enderror</label>
                    <label><span class="crm-label">{{ __('app.date_of_birth') }}<span class="text-rose-600 {{ old('registrant_type', $portalUser->registrant_type?->value) === 'adult_athlete' ? '' : 'hidden' }}" data-participant-required-marker>*</span></span><input type="date" name="date_of_birth" value="{{ old('date_of_birth', $portalUser->profileParticipant?->date_of_birth?->format('Y-m-d')) }}" @required(old('registrant_type', $portalUser->registrant_type?->value) === 'adult_athlete') class="crm-field" data-participant-required-input>@error('date_of_birth')<span class="crm-help">{{ $message }}</span>@enderror</label>
                @endif
            </div>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_profile_contact_details') }}</h2>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <label><span class="crm-label">{{ __('app.email') }}</span><input type="email" name="email" value="{{ old('email', $portalUser->email) }}" required class="crm-field">@error('email')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.phone') }}</span><input name="phone" value="{{ old('phone', $portalUser->phone) }}" @required($isRegistrant) class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}">@error('phone')<span class="crm-help">{{ $message }}</span>@enderror</label>

                @if ($isRegistrant)
                    <label><span class="crm-label">{{ __('app.city') }}</span><input name="city" value="{{ old('city', $portalUser->city) }}" required class="crm-field">@error('city')<span class="crm-help">{{ $message }}</span>@enderror</label>
                    <label><span class="crm-label">{{ __('app.festival_studio_school') }}</span><input name="studio_name" value="{{ old('studio_name', $portalUser->studio_name) }}" required class="crm-field">@error('studio_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                @endif

                @unless ($isGuest)<label><span class="crm-label">{{ __('app.festival_instagram_url') }}</span><input type="url" name="instagram_url" value="{{ old('instagram_url', $portalUser->instagram_url) }}" class="crm-field">@error('instagram_url')<span class="crm-help">{{ $message }}</span>@enderror</label>@endunless
            </div>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_profile_preferences_security') }}</h2>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <label><span class="crm-label">{{ __('app.language') }}</span><select name="locale" class="crm-field"><option value="uk" @selected(old('locale', $portalUser->locale) === 'uk')>Українська</option><option value="en" @selected(old('locale', $portalUser->locale) === 'en')>English</option></select>@error('locale')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <div class="hidden sm:block"></div>
                <label><span class="crm-label">{{ __('app.password') }}</span><input type="password" name="password" @required(! $portalUser->exists && ! $isGuest) autocomplete="new-password" class="crm-field"><span class="mt-1 block text-xs font-medium text-slate-500">{{ $isGuest ? __('app.festival_guest_password_help') : ($portalUser->exists ? __('app.festival_password_edit_help') : __('app.festival_password_create_help')) }}</span>@error('password')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.password_confirmation') }}</span><input type="password" name="password_confirmation" @required(! $portalUser->exists && ! $isGuest) autocomplete="new-password" class="crm-field">@error('password_confirmation')<span class="crm-help">{{ $message }}</span>@enderror</label>
            </div>

            <input type="hidden" name="is_active" value="0">
            <label class="mt-5 flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $portalUser->is_active ?? true)) class="crm-checkbox">{{ __('app.active') }}</label>
            @error('is_active')<span class="crm-help">{{ $message }}</span>@enderror
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <x-ui.button :href="($returnTo ?? null) === 'ticket-issuance' ? route('dashboard.accounts.festivals.tickets.issue', [$account, $edition]) : route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => $directoryTab])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
            <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button>
        </div>
    </form>

    @if ($portalUser->exists && $isRegistrant)
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-2xl font-semibold text-slate-950">{{ __('app.festival_roster') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_roster_staff_copy') }}</p></div>
                <x-ui.button :href="route('dashboard.accounts.festivals.users.participants.create', [$account, $edition, $portalUser])"><x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_participant') }}</x-ui.button>
            </div>
            <x-ui.panel padding="none" class="overflow-hidden">
                @forelse ($portalUser->participants as $participant)
                    <div class="crm-row lg:grid-cols-[minmax(0,1fr)_160px_auto] lg:items-center">
                        <div><p class="font-semibold text-slate-950">{{ $participant->displayName() }}</p><p class="mt-1 text-sm text-slate-500">{{ $participant->date_of_birth->format('d.m.Y') }}@if($participant->is_profile_owner) · {{ __('app.festival_profile') }}@endif</p></div>
                        <div class="text-sm text-slate-500">{{ trans_choice('app.festival_entries_usage_count', $participant->entries_count, ['count' => $participant->entries_count]) }}@if($participant->archived_at)<span class="mt-1 block">{{ __('app.archived') }}</span>@endif</div>
                        <div class="flex justify-end gap-2">
                            @unless($participant->is_profile_owner)
                                <x-ui.action-button :href="route('dashboard.accounts.festivals.users.participants.edit', [$account, $edition, $portalUser, $participant])" :label="__('app.edit')" />
                                @unless($participant->archived_at)
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
    @endif
    @elseif ($portalUser->exists && $isRegistrant)
        <section aria-labelledby="festival-participant-notifications-title">
            <div>
                <h2 id="festival-participant-notifications-title" class="text-xl font-semibold text-slate-950">{{ __('app.festival_participant_notifications_history') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_participant_notifications_history_copy') }}</p>
            </div>

            <div class="mt-5 space-y-4">
                @forelse ($festivalNotifications as $notification)
                    <x-festivals.staff.notification-card
                        :$notification
                        :timezone="$notification->edition?->timezone ?? $edition->timezone"
                        :show-recipient="false"
                        :show-context="true"
                    />
                @empty
                    <x-ui.empty-state icon="bell">{{ __('app.festival_participant_notifications_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
            <div>{{ $festivalNotifications->links() }}</div>
        </section>
    @endif
</x-festivals.staff.workspace>
@endsection
