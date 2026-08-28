@php
    $isJudge = $portalUser->role === \App\Enums\FestivalPortalRole::Judge;
    $isGuest = $portalUser->role === \App\Enums\FestivalPortalRole::Guest;
    $isRegistrant = $portalUser->role === \App\Enums\FestivalPortalRole::Registrant;
    $directoryTab = $isJudge ? 'judges' : ($isGuest ? 'guests' : 'participants');
    $selectedRegistrantType = old('registrant_type', $portalUser->registrant_type?->value ?? \App\Enums\FestivalRegistrantType::AdultAthlete->value);
    $registrantTypeLocked = $portalUser->registrantTypeIsLocked();
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
        @include('festivals.staff.users._detail-nav', ['activeDetailPage' => 'profile'])
    @endif

    <form method="POST" enctype="multipart/form-data" action="{{ $portalUser->exists ? route('dashboard.accounts.festivals.users.update', [$account, $edition, $portalUser]) : route('dashboard.accounts.festivals.users.store', [$account, $edition, $portalUser->role->value]) }}" class="max-w-4xl space-y-6">
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
                    <div class="sm:col-span-2">
                        <label><span class="crm-label">{{ __('app.festival_profile_type') }}</span><select name="registrant_type" required class="crm-field" data-festival-registrant-type aria-describedby="staff-registrant-type-warning" @disabled($registrantTypeLocked)>@foreach (\App\Enums\FestivalRegistrantType::selectableCases($portalUser->registrant_type, $registrantTypeLocked) as $type)<option value="{{ $type->value }}" @selected($selectedRegistrantType === $type->value)>{{ __('app.festival_registrant_'.$type->value) }}</option>@endforeach</select>@if($registrantTypeLocked)<input type="hidden" name="registrant_type" value="{{ $portalUser->registrant_type?->value }}">@endif @error('registrant_type')<span class="crm-help">{{ $message }}</span>@enderror</label>
                        <div id="staff-registrant-type-warning" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                            <p>{{ __('app.festival_registrant_type_warning') }}</p>
                            @if($portalUser->registrant_type === \App\Enums\FestivalRegistrantType::Guardian)<p class="mt-2">{{ __('app.festival_registrant_guardian_legacy_warning') }}</p>@endif
                        </div>
                    </div>
                    <label><span class="crm-label">{{ __('app.date_of_birth') }}<span class="text-rose-600 {{ $selectedRegistrantType === 'adult_athlete' ? '' : 'hidden' }}" data-participant-required-marker>*</span></span><input type="date" name="date_of_birth" value="{{ old('date_of_birth', $portalUser->profileParticipant?->date_of_birth?->format('Y-m-d')) }}" @required($selectedRegistrantType === 'adult_athlete') class="crm-field" data-participant-required-input>@error('date_of_birth')<span class="crm-help">{{ $message }}</span>@enderror</label>
                @endif
            </div>

            @if($isRegistrant)
                <div class="mt-5 border-t border-stone-200 pt-5">
                    <span class="crm-label">{{ __('app.photo') }}</span>
                    <div class="mt-2 flex flex-col gap-4 sm:flex-row sm:items-center">
                        @if($portalUser->avatar_path)
                            <img src="{{ route('dashboard.accounts.festivals.users.photo', [$account, $edition, $portalUser]) }}" alt="" class="h-20 w-20 rounded-full border border-stone-200 object-cover">
                        @endif
                        <div class="min-w-0 flex-1">
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="crm-field">
                            <p class="mt-1 text-sm text-slate-500">{{ __('app.festival_photo_help') }}</p>
                            @error('photo')<span class="crm-help">{{ $message }}</span>@enderror
                            @if($portalUser->avatar_path)<label class="mt-3 flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_photo" value="1" class="crm-checkbox">{{ __('app.festival_remove_photo') }}</label>@endif
                        </div>
                    </div>
                </div>
            @endif
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

                @unless ($isGuest)<label><span class="crm-label">{{ __('app.festival_instagram_url') }}</span><input name="instagram_url" inputmode="url" value="{{ old('instagram_url', $portalUser->instagram_url) }}" placeholder="{{ \App\Rules\FestivalSocialLink::instagram()->placeholder() }}" class="crm-field"><span class="mt-1 block text-sm text-slate-500">{{ \App\Rules\FestivalSocialLink::instagram()->help() }}</span>@error('instagram_url')<span class="crm-help">{{ $message }}</span>@enderror</label>@endunless
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

</x-festivals.staff.workspace>
@endsection
