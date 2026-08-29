@extends('layouts.app')

@section('title', __('app.festival_communication_tab_announcements').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_communication_tab_announcements')" :copy="__('app.festival_announcements_copy')">
        <x-slot:actions>
            <x-ui.button type="button" data-festival-announcement-open>
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_create_announcement') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('festivals.staff._communication-navigation')

    <div class="space-y-4">
        @forelse ($announcements as $announcement)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm"><div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div class="min-w-0"><h2 class="text-lg font-semibold text-slate-950">{{ $announcement->subject }}</h2><p class="mt-2 whitespace-pre-line break-words text-sm text-slate-600">{{ $announcement->body }}</p></div><span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_announcement_status_'.$announcement->status) }}</span></div><p class="mt-4 text-xs text-slate-500">{{ $announcement->scheduled_at?->timezone($edition->timezone)->format('d.m.Y H:i') }}</p></article>
        @empty
            <x-ui.empty-state icon="megaphone">{{ __('app.festival_announcements_empty') }}</x-ui.empty-state>
        @endforelse
    </div>
    <div>{{ $announcements->links() }}</div>

    <div class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/45 p-4" role="dialog" aria-modal="true" aria-labelledby="festival-announcement-modal-title" data-festival-announcement-modal data-open="{{ $errors->hasAny(['subject', 'body', 'scheduled_at']) || old('subject') !== null || old('body') !== null || old('scheduled_at') !== null ? 'true' : 'false' }}">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" data-festival-announcement-panel>
            <div class="flex items-start justify-between gap-4"><div><h2 id="festival-announcement-modal-title" class="text-xl font-semibold text-slate-950">{{ __('app.festival_create_announcement') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_announcement_audience_copy') }}</p></div><button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900" aria-label="{{ __('app.close') }}" data-festival-announcement-close><x-ui.icon name="x" class="h-5 w-5" /></button></div>
            <form method="POST" action="{{ route('dashboard.accounts.festivals.announcements.store', [$account, $edition]) }}" class="mt-6 space-y-5">
                @csrf
                <label class="block"><span class="crm-label">{{ __('app.subject') }}</span><input name="subject" value="{{ old('subject') }}" maxlength="255" required class="crm-field" autofocus>@error('subject')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="crm-label">{{ __('app.message') }}</span><textarea name="body" required rows="8" maxlength="50000" class="crm-field">{{ old('body') }}</textarea>@error('body')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="crm-label">{{ __('app.festival_send_at') }}</span><input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}" class="crm-field"><span class="crm-help">{{ __('app.festival_send_at_help', ['timezone' => $edition->timezone]) }}</span>@error('scheduled_at')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <div class="flex flex-wrap justify-end gap-2"><x-ui.button type="button" variant="secondary" data-festival-announcement-close>{{ __('app.cancel') }}</x-ui.button><x-ui.button type="submit"><x-ui.icon name="send" class="h-4 w-4" /> {{ __('app.send') }}</x-ui.button></div>
            </form>
        </div>
    </div>
</x-festivals.staff.workspace>
@endsection
