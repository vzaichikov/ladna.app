@php
    $isJudge = $portalUser->role === \App\Enums\FestivalPortalRole::Judge;
    $tab = $isJudge ? 'judges' : 'participants';
@endphp

@extends('layouts.app')

@section('title', ($portalUser->exists ? __('app.festival_edit_user') : __('app.festival_add_user')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header
        :title="$portalUser->exists ? __('app.festival_edit_user') : ($isJudge ? __('app.festival_add_judge_profile') : __('app.festival_add_registrant'))"
        :copy="__('app.festival_user_form_copy')"
    />

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">{{ session('status') }}</div>
    @endif

    <x-ui.panel class="max-w-4xl">
        <form method="POST" action="{{ $portalUser->exists ? route('dashboard.accounts.festivals.users.update', [$account, $edition, $portalUser]) : route('dashboard.accounts.festivals.users.store', [$account, $edition, $portalUser->role->value]) }}" class="space-y-6">
            @csrf
            @if ($portalUser->exists) @method('PUT') @endif

            <div class="rounded-xl border border-stone-200 bg-slate-50 p-4">
                <p class="crm-label">{{ __('app.role') }}</p>
                <p class="mt-1 font-semibold text-slate-950">{{ $isJudge ? __('app.festival_role_judge') : __('app.festival_role_registrant') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.festival_role_immutable_copy') }}</p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <label><span class="crm-label">{{ __('app.first_name') }}</span><input name="first_name" value="{{ old('first_name', $portalUser->first_name) }}" required class="crm-field">@error('first_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.last_name') }}</span><input name="last_name" value="{{ old('last_name', $portalUser->last_name) }}" required class="crm-field">@error('last_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.patronymic') }}</span><input name="patronymic" value="{{ old('patronymic', $portalUser->patronymic) }}" class="crm-field">@error('patronymic')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.email') }}</span><input type="email" name="email" value="{{ old('email', $portalUser->email) }}" required class="crm-field">@error('email')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.phone') }}</span><input name="phone" value="{{ old('phone', $portalUser->phone) }}" @required(! $isJudge) class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}">@error('phone')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.language') }}</span><select name="locale" class="crm-field"><option value="uk" @selected(old('locale', $portalUser->locale) === 'uk')>Українська</option><option value="en" @selected(old('locale', $portalUser->locale) === 'en')>English</option></select></label>

                @unless ($isJudge)
                    <label><span class="crm-label">{{ __('app.festival_profile_type') }}</span><select name="registrant_type" required class="crm-field">@foreach (\App\Enums\FestivalRegistrantType::cases() as $type)<option value="{{ $type->value }}" @selected(old('registrant_type', $portalUser->registrant_type?->value) === $type->value)>{{ __('app.festival_registrant_'.$type->value) }}</option>@endforeach</select>@error('registrant_type')<span class="crm-help">{{ $message }}</span>@enderror</label>
                    <label><span class="crm-label">{{ __('app.city') }}</span><input name="city" value="{{ old('city', $portalUser->city) }}" required class="crm-field">@error('city')<span class="crm-help">{{ $message }}</span>@enderror</label>
                    <label><span class="crm-label">{{ __('app.festival_studio_school') }}</span><input name="studio_name" value="{{ old('studio_name', $portalUser->studio_name) }}" required class="crm-field">@error('studio_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                    <label><span class="crm-label">Instagram</span><input type="url" name="instagram_url" value="{{ old('instagram_url', $portalUser->instagram_url) }}" class="crm-field">@error('instagram_url')<span class="crm-help">{{ $message }}</span>@enderror</label>
                @endunless

                <label><span class="crm-label">{{ __('app.password') }}</span><input type="password" name="password" @required(! $portalUser->exists) autocomplete="new-password" class="crm-field"><span class="mt-1 block text-xs font-medium text-slate-500">{{ $portalUser->exists ? __('app.festival_password_edit_help') : __('app.festival_password_create_help') }}</span>@error('password')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.password_confirmation') }}</span><input type="password" name="password_confirmation" @required(! $portalUser->exists) autocomplete="new-password" class="crm-field"></label>
            </div>

            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $portalUser->is_active ?? true)) class="crm-checkbox">{{ __('app.active') }}</label>
            @error('is_active')<span class="crm-help">{{ $message }}</span>@enderror

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.festivals.users.index', [$account, $edition, 'tab' => $tab])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>

    @if ($portalUser->exists && ! $isJudge)
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="text-2xl font-semibold text-slate-950">{{ __('app.festival_roster') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_roster_staff_copy') }}</p></div>
                <x-ui.button :href="route('dashboard.accounts.festivals.users.participants.create', [$account, $edition, $portalUser])"><x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_participant') }}</x-ui.button>
            </div>
            <x-ui.panel padding="none" class="overflow-hidden">
                @forelse ($portalUser->participants as $participant)
                    <div class="crm-row lg:grid-cols-[minmax(0,1fr)_160px_auto] lg:items-center">
                        <div><p class="font-semibold text-slate-950">{{ $participant->displayName() }}</p><p class="mt-1 text-sm text-slate-500">{{ $participant->date_of_birth->format('d.m.Y') }}</p></div>
                        <div class="text-sm text-slate-500">{{ trans_choice('app.festival_entries_usage_count', $participant->entries_count, ['count' => $participant->entries_count]) }}@if($participant->archived_at)<span class="mt-1 block">{{ __('app.archived') }}</span>@endif</div>
                        <div class="flex justify-end gap-2"><x-ui.action-button :href="route('dashboard.accounts.festivals.users.participants.edit', [$account, $edition, $portalUser, $participant])" :label="__('app.edit')" />@unless($participant->archived_at)<x-ui.action-button :href="route('dashboard.accounts.festivals.users.participants.archive', [$account, $edition, $portalUser, $participant])" icon="archive" :label="__('app.archive')" />@endunless</div>
                    </div>
                @empty
                    <x-ui.empty-state :title="__('app.festival_participants_empty')" icon="users" class="m-5" />
                @endforelse
            </x-ui.panel>
        </section>
    @endif
</x-festivals.staff.workspace>
@endsection
