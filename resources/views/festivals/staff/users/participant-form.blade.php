@extends('layouts.app')

@section('title', ($participant->exists ? __('app.festival_edit_participant') : __('app.festival_add_participant')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$participant->exists ? __('app.festival_edit_participant') : __('app.festival_add_participant')" :copy="$portalUser->displayName()" />
    <x-ui.panel class="max-w-3xl">
        <form method="POST" enctype="multipart/form-data" action="{{ $participant->exists ? route('dashboard.accounts.festivals.users.participants.update', [$account, $edition, $portalUser, $participant]) : route('dashboard.accounts.festivals.users.participants.store', [$account, $edition, $portalUser]) }}" class="space-y-5">
            @csrf
            @if ($participant->exists) @method('PUT') @endif
            <div class="grid gap-5 sm:grid-cols-2">
                <label><span class="crm-label">{{ __('app.first_name') }}</span><input name="first_name" value="{{ old('first_name', $participant->first_name) }}" required class="crm-field">@error('first_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.last_name') }}</span><input name="last_name" value="{{ old('last_name', $participant->last_name) }}" required class="crm-field">@error('last_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.patronymic') }}</span><input name="patronymic" value="{{ old('patronymic', $participant->patronymic) }}" class="crm-field"></label>
                <label><span class="crm-label">{{ __('app.date_of_birth') }}</span><input type="date" name="date_of_birth" value="{{ old('date_of_birth', $participant->date_of_birth?->format('Y-m-d')) }}" required class="crm-field">@error('date_of_birth')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <fieldset class="sm:col-span-2">
                    <legend class="crm-label">{{ __('app.festival_team_member_type') }}</legend>
                    @if($memberTypeLocked)<input type="hidden" name="member_type" value="{{ $participant->member_type->value }}">@endif
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        @foreach(\App\Enums\FestivalTeamMemberType::cases() as $memberType)
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-stone-200 p-4 has-checked:border-brand-500 has-checked:bg-brand-50">
                                <input type="radio" name="member_type" value="{{ $memberType->value }}" class="crm-radio mt-1" @checked(old('member_type', $participant->member_type?->value ?? \App\Enums\FestivalTeamMemberType::Performer->value) === $memberType->value) @disabled($memberTypeLocked) required>
                                <span><strong class="block text-slate-900">{{ __('app.festival_team_member_type_'.$memberType->value) }}</strong><span class="mt-1 block text-sm text-slate-500">{{ __('app.festival_team_member_type_'.$memberType->value.'_help') }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    @if($memberTypeLocked)<p class="mt-2 text-sm font-semibold text-amber-800">{{ __('app.festival_team_member_type_in_use') }}</p>@endif
                    @error('member_type')<span class="crm-help">{{ $message }}</span>@enderror
                </fieldset>
                <div class="sm:col-span-2">
                    <span class="crm-label">{{ __('app.photo') }}</span>
                    <div class="mt-2 flex flex-col gap-4 sm:flex-row sm:items-center">
                        @if($participant->photo_path)
                            <img src="{{ route('dashboard.accounts.festivals.users.participants.photo', [$account, $edition, $portalUser, $participant]) }}" alt="" class="h-20 w-20 rounded-full border border-stone-200 object-cover">
                        @endif
                        <div class="min-w-0 flex-1">
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="crm-field">
                            <p class="mt-1 text-sm text-slate-500">{{ __('app.festival_photo_help') }}</p>
                            @error('photo')<span class="crm-help">{{ $message }}</span>@enderror
                            @if($participant->photo_path)<label class="mt-3 flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_photo" value="1" class="crm-checkbox">{{ __('app.festival_remove_photo') }}</label>@endif
                        </div>
                    </div>
                </div>
                <label class="sm:col-span-2"><span class="crm-label">{{ __('app.notes') }}</span><textarea name="notes" rows="4" class="crm-field">{{ old('notes', $participant->notes) }}</textarea>@error('notes')<span class="crm-help">{{ $message }}</span>@enderror</label>
            </div>
            <div class="flex flex-wrap gap-2"><x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button><x-ui.button :href="route('dashboard.accounts.festivals.users.team', [$account, $edition, $portalUser])" variant="secondary">{{ __('app.cancel') }}</x-ui.button></div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
