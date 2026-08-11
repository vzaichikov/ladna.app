@extends('layouts.app')

@section('title', ($document->exists ? __('app.festival_edit_document') : __('app.festival_add_document')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$document->exists ? __('app.festival_edit_document') : __('app.festival_add_document')" :copy="__('app.festival_document_form_copy')" />
    <x-ui.panel>
        <form method="POST" enctype="multipart/form-data" action="{{ $document->exists ? route('dashboard.accounts.festivals.documents.update', [$account, $edition, $document]) : route('dashboard.accounts.festivals.documents.store', [$account, $edition]) }}" class="grid gap-5 sm:grid-cols-2">
            @csrf
            @if ($document->exists) @method('PUT') @endif
            <label><span class="crm-label">{{ __('app.title') }}</span><input name="title" value="{{ old('title', $document->title) }}" maxlength="255" required class="crm-field">@error('title') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <label><span class="crm-label">{{ __('app.type') }}</span><select name="kind" class="crm-field">@foreach (['rules', 'schedule', 'guide', 'document'] as $kind)<option value="{{ $kind }}" @selected(old('kind', $document->kind ?? 'document') === $kind)>{{ __('app.festival_document_kind_'.$kind) }}</option>@endforeach</select>@error('kind') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <label><span class="crm-label">{{ __('app.visibility') }}</span><select name="visibility" class="crm-field">@foreach (['public', 'portal', 'staff'] as $visibility)<option value="{{ $visibility }}" @selected(old('visibility', $document->visibility ?? 'public') === $visibility)>{{ __('app.festival_visibility_'.$visibility) }}</option>@endforeach</select>@error('visibility') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <label><span class="crm-label">{{ __('app.file') }}</span><input type="file" name="file" @required(! $document->exists) class="crm-field" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">@if ($document->exists)<span class="crm-help">{{ __('app.festival_current_file') }}: {{ $document->original_name }}</span>@endif @error('file') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $document->is_active ?? true))>{{ __('app.active') }}</label>
            <div class="flex flex-wrap justify-end gap-2"><x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button><x-ui.button :href="route('dashboard.accounts.festivals.settings.content.documents', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button></div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
