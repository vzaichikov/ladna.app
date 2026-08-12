@extends('layouts.app')

@section('title', ($participant->exists ? __('app.festival_edit_participant') : __('app.festival_add_participant')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$participant->exists ? __('app.festival_edit_participant') : __('app.festival_add_participant')" :copy="$portalUser->displayName()" />
    <x-ui.panel class="max-w-3xl">
        <form method="POST" action="{{ $participant->exists ? route('dashboard.accounts.festivals.users.participants.update', [$account, $edition, $portalUser, $participant]) : route('dashboard.accounts.festivals.users.participants.store', [$account, $edition, $portalUser]) }}" class="space-y-5">
            @csrf
            @if ($participant->exists) @method('PUT') @endif
            <div class="grid gap-5 sm:grid-cols-2">
                <label><span class="crm-label">{{ __('app.first_name') }}</span><input name="first_name" value="{{ old('first_name', $participant->first_name) }}" required class="crm-field">@error('first_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.last_name') }}</span><input name="last_name" value="{{ old('last_name', $participant->last_name) }}" required class="crm-field">@error('last_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.patronymic') }}</span><input name="patronymic" value="{{ old('patronymic', $participant->patronymic) }}" class="crm-field"></label>
                <label><span class="crm-label">{{ __('app.date_of_birth') }}</span><input type="date" name="date_of_birth" value="{{ old('date_of_birth', $participant->date_of_birth?->format('Y-m-d')) }}" required class="crm-field">@error('date_of_birth')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label class="sm:col-span-2"><span class="crm-label">{{ __('app.notes') }}</span><textarea name="notes" rows="4" class="crm-field">{{ old('notes', $participant->notes) }}</textarea>@error('notes')<span class="crm-help">{{ $message }}</span>@enderror</label>
            </div>
            <div class="flex flex-wrap gap-2"><x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button><x-ui.button :href="route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser])" variant="secondary">{{ __('app.cancel') }}</x-ui.button></div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
