@extends('layouts.app')

@section('title', __('app.festival_stream_settings').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_stream_settings')" :copy="__('app.festival_stream_settings_copy')" />

    @if (! $stream)
        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm text-sky-950">
            <strong>{{ __('app.festival_stream_not_configured') }}</strong>
            <p class="mt-1">{{ __('app.festival_stream_settings_copy') }}</p>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-crm"><span class="text-sm text-slate-600">{{ __('app.status') }}</span><strong class="mt-1 block text-lg text-slate-950">{{ $stream->is_enabled ? __('app.active') : __('app.inactive') }}</strong></div>
            <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-crm"><span class="text-sm text-slate-600">{{ __('app.festival_online_stream') }}</span><strong class="mt-1 block text-lg text-slate-950">{{ $streamStatus === null ? __('app.festival_stream_status_unavailable') : ($streamStatus['publisher_online'] ? __('app.festival_stream_publisher_online') : __('app.festival_stream_publisher_offline')) }}</strong></div>
            <div class="rounded-2xl border border-stone-200 bg-white p-4 shadow-crm"><span class="text-sm text-slate-600">{{ __('app.festival_stream_readers', ['count' => $streamStatus['readers'] ?? 0]) }}</span><strong class="mt-1 block text-lg text-slate-950">{{ $streamStatus['readers'] ?? 0 }}</strong></div>
        </div>
    @endif

    <x-ui.panel class="max-w-4xl">
        <form method="POST" action="{{ route('dashboard.accounts.festivals.online-stream.update', [$account, $edition]) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="grid gap-5 md:grid-cols-2">
                <label>
                    <span class="crm-label">{{ __('app.festival_stream_opens_at') }}</span>
                    <input type="datetime-local" name="opens_at" value="{{ old('opens_at', $stream?->opens_at?->timezone($edition->timezone)->format('Y-m-d\TH:i')) }}" class="crm-field">
                    @error('opens_at') <span class="crm-help text-rose-600">{{ $message }}</span> @enderror
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_stream_closes_at') }}</span>
                    <input type="datetime-local" name="closes_at" value="{{ old('closes_at', $stream?->closes_at?->timezone($edition->timezone)->format('Y-m-d\TH:i')) }}" class="crm-field">
                    @error('closes_at') <span class="crm-help text-rose-600">{{ $message }}</span> @enderror
                </label>
                <label class="md:col-span-2">
                    <span class="crm-label">{{ __('app.festival_stream_override') }}</span>
                    <select name="playback_override" required class="crm-field">
                        @foreach (\App\Enums\FestivalStreamOverride::cases() as $override)
                            <option value="{{ $override->value }}" @selected(old('playback_override', $stream?->playback_override?->value ?? 'automatic') === $override->value)>{{ __('app.festival_stream_override_'.$override->value) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <input type="hidden" name="is_enabled" value="0">
            @if ($stream)
                <label class="flex items-start gap-3 rounded-2xl border border-slate-200 p-4 text-sm text-slate-700">
                    <input type="checkbox" name="is_enabled" value="1" class="crm-checkbox mt-0.5" @checked(old('is_enabled', $stream->is_enabled))>
                    <span><strong class="block text-slate-950">{{ __('app.festival_stream_enabled') }}</strong>{{ __('app.festival_online_ticket_requires_stream') }}</span>
                </label>
                @error('is_enabled') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
            @endif

            <input type="hidden" name="rotate_publisher_token" value="0">
            @if ($stream)
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><span class="crm-label">{{ __('app.festival_stream_obs_server') }}</span><code class="mt-1 block break-all rounded-lg bg-white p-3 text-xs">{{ config('services.festival_stream.obs_server') ?: __('app.not_configured') }}</code></div>
                        <div><span class="crm-label">{{ __('app.festival_stream_obs_key') }}</span><code class="mt-1 block break-all rounded-lg bg-white p-3 text-xs">{{ $stream->path }}?token={{ $stream->publisher_token_encrypted }}</code></div>
                    </div>
                    <p class="mt-3 text-xs text-slate-600">{{ __('app.festival_stream_obs_private_help') }}</p>
                    <label class="mt-4 flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="rotate_publisher_token" value="1" class="crm-checkbox">{{ __('app.festival_stream_rotate_key') }}</label>
                </div>
            @endif

            <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" /> {{ $stream ? __('app.save') : __('app.festival_stream_configure') }}</x-ui.button>
        </form>
    </x-ui.panel>

    @if ($stream)
        <x-ui.panel class="max-w-4xl">
            <h2 class="text-base font-semibold text-slate-950">{{ __('app.festival_stream_reset_leases') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_stream_reset_leases_help') }}</p>
            <form method="POST" action="{{ route('dashboard.accounts.festivals.online-stream.reset-leases', [$account, $edition]) }}" class="mt-4">
                @csrf
                @method('DELETE')
                <x-ui.button type="submit" variant="danger">{{ __('app.festival_stream_reset_leases') }}</x-ui.button>
            </form>
        </x-ui.panel>
    @endif
</x-festivals.staff.workspace>
@endsection
