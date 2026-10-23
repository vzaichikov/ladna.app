@extends('layouts.app')

@section('title', ($sponsor->exists ? __('app.festival_sponsor_edit') : __('app.festival_sponsor_add')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$sponsor->exists ? __('app.festival_sponsor_edit') : __('app.festival_sponsor_add')" :copy="__('app.festival_sponsor_form_copy')" />

    <form method="POST" enctype="multipart/form-data" action="{{ $sponsor->exists ? route('dashboard.accounts.festivals.sponsors.update', [$account, $edition, $sponsor]) : route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]) }}" class="max-w-4xl space-y-5">
        @csrf
        @if($sponsor->exists) @method('PUT') @endif

        <x-ui.panel class="space-y-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_sponsor_identity') }}</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block">
                    <span class="crm-label">{{ __('app.name') }} <span class="text-red-600" aria-hidden="true">*</span></span>
                    <input name="name" value="{{ old('name', $sponsor->name) }}" maxlength="255" required class="crm-field" aria-describedby="sponsor-name-help">
                    <span id="sponsor-name-help" class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_sponsor_name_help') }}</span>
                    @error('name')<span class="block text-sm text-red-600" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.festival_sponsor_url') }}</span>
                    <input type="url" name="url" value="{{ old('url', $sponsor->url) }}" maxlength="2048" class="crm-field" placeholder="https://" aria-describedby="sponsor-url-help">
                    <span id="sponsor-url-help" class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_sponsor_url_help') }}</span>
                    @error('url')<span class="block text-sm text-red-600" role="alert">{{ $message }}</span>@enderror
                </label>
            </div>

            <div>
                <label for="sponsor-logo" class="crm-label">{{ __('app.festival_sponsor_logo') }} @unless($sponsor->exists)<span class="text-red-600" aria-hidden="true">*</span>@endunless</label>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    @if($sponsor->logoUrl())
                        <div class="flex h-28 w-40 shrink-0 items-center justify-center rounded-xl border border-stone-200 bg-white p-4">
                            <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}" class="max-h-full w-full object-contain">
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <input id="sponsor-logo" type="file" name="logo" accept="image/jpeg,image/png,image/webp" @required(!$sponsor->exists) class="crm-field" aria-describedby="sponsor-logo-help">
                        <p id="sponsor-logo-help" class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_sponsor_logo_help') }}</p>
                        @if($sponsor->exists)<p class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_sponsor_logo_replace_help') }}</p>@endif
                        @error('logo')<span class="block text-sm text-red-600" role="alert">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            <label class="block">
                <span class="crm-label">{{ __('app.festival_sponsor_short_description') }} <span class="text-red-600" aria-hidden="true">*</span></span>
                <textarea name="short_description" rows="3" maxlength="500" required class="crm-field" aria-describedby="sponsor-description-help">{{ old('short_description', $sponsor->short_description) }}</textarea>
                <span id="sponsor-description-help" class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_sponsor_short_description_help') }}</span>
                @error('short_description')<span class="block text-sm text-red-600" role="alert">{{ $message }}</span>@enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.festival_sponsor_giveaway') }}</span>
                <textarea name="giveaway_description" rows="5" maxlength="10000" class="crm-field" aria-describedby="sponsor-giveaway-help">{{ old('giveaway_description', $sponsor->giveaway_description) }}</textarea>
                <span id="sponsor-giveaway-help" class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_sponsor_giveaway_help') }}</span>
                @error('giveaway_description')<span class="block text-sm text-red-600" role="alert">{{ $message }}</span>@enderror
            </label>
        </x-ui.panel>

        <x-ui.panel class="space-y-3">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_sponsor_placement') }}</h2>
            <input type="hidden" name="show_on_all_pages" value="0">
            <label class="flex items-start gap-3 text-sm text-slate-700">
                <input type="checkbox" name="show_on_all_pages" value="1" @checked(old('show_on_all_pages', $sponsor->show_on_all_pages)) class="mt-1 rounded border-stone-300" aria-describedby="sponsor-placement-help">
                <span>
                    <span class="font-semibold">{{ __('app.festival_sponsor_show_on_all_pages') }}</span>
                    <span id="sponsor-placement-help" class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_sponsor_show_on_all_pages_help') }}</span>
                </span>
            </label>
            @error('show_on_all_pages')<span class="block text-sm text-red-600" role="alert">{{ $message }}</span>@enderror
        </x-ui.panel>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
        </div>
    </form>
</x-festivals.staff.workspace>
@endsection
