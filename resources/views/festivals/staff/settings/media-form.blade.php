@extends('layouts.app')

@section('title', ($media->exists ? __('app.festival_edit_media') : __('app.festival_add_media')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$media->exists ? __('app.festival_edit_media') : __('app.festival_add_media')" :copy="__('app.festival_media_form_copy')" />
    <x-ui.panel>
        <form method="POST" action="{{ $media->exists ? route('dashboard.accounts.festivals.media.update', [$account, $edition, $media]) : route('dashboard.accounts.festivals.media.store', [$account, $edition]) }}" class="grid gap-5 sm:grid-cols-2">
            @csrf
            @if ($media->exists) @method('PUT') @endif
            <label><span class="crm-label">{{ __('app.type') }}</span><select name="kind" class="crm-field"><option value="image" @selected(old('kind', $media->kind ?? 'image') === 'image')>{{ __('app.festival_media_kind_image') }}</option><option value="video" @selected(old('kind', $media->kind) === 'video')>{{ __('app.festival_media_kind_video') }}</option></select>@error('kind') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <label><span class="crm-label">{{ __('app.festival_media_url') }}</span><input type="url" name="external_url" value="{{ old('external_url', $media->external_url) }}" maxlength="2048" required class="crm-field" placeholder="https://">@error('external_url') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <label><span class="crm-label">{{ __('app.festival_alt_text') }}</span><input name="alt_text" value="{{ old('alt_text', $media->alt_text) }}" maxlength="255" class="crm-field">@error('alt_text') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <label><span class="crm-label">{{ __('app.caption') }}</span><input name="caption" value="{{ old('caption', $media->caption) }}" maxlength="500" class="crm-field">@error('caption') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <div class="flex flex-wrap items-center gap-5 sm:col-span-2"><input type="hidden" name="is_cover" value="0"><label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_cover" value="1" @checked(old('is_cover', $media->is_cover ?? false))>{{ __('app.festival_cover') }}</label><input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $media->is_active ?? true))>{{ __('app.active') }}</label></div>
            <div class="flex flex-wrap justify-end gap-2 sm:col-span-2"><x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button><x-ui.button :href="route('dashboard.accounts.festivals.settings.content.media', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button></div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
