@extends('layouts.app')

@section('title', __('app.festival_stream_settings').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_stream_settings')" :copy="__('app.festival_stream_settings_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('help.show', 'festivals').'#help-section-festivals-online-streaming'" variant="secondary" target="_blank" rel="noopener">
                <x-ui.icon name="circle-help" class="h-4 w-4" />
                {{ __('app.festival_stream_full_help') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $stream)
        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm text-sky-950">
            <strong>{{ __('app.festival_stream_not_configured') }}</strong>
            <p class="mt-1">{{ __('app.festival_stream_settings_copy') }}</p>
        </div>
    @else
        @php
            $serverOnline = $streamStatus !== null;
            $publisherOnline = $streamStatus['publisher_online'] ?? false;
            $readerCount = $streamStatus['readers'] ?? 0;
            $trackCodecs = $streamStatus['tracks'] ?? [];
        @endphp
        <section
            data-festival-stream-status
            data-status-url="{{ route('dashboard.accounts.festivals.online-stream.status', [$account, $edition]) }}"
            data-server-online="{{ __('app.festival_stream_server_online') }}"
            data-server-unavailable="{{ __('app.festival_stream_status_unavailable') }}"
            data-obs-online="{{ __('app.festival_stream_publisher_online') }}"
            data-obs-offline="{{ __('app.festival_stream_publisher_offline') }}"
            data-obs-connected-template="{{ __('app.festival_stream_connected_since', ['time' => ':time']) }}"
            data-obs-tracks-template="{{ __('app.festival_stream_tracks', ['tracks' => ':tracks']) }}"
            data-obs-waiting="{{ __('app.festival_stream_waiting_for_obs_help') }}"
            data-viewers-template="{{ __('app.festival_stream_readers', ['count' => ':count']) }}"
            data-viewers-empty="{{ __('app.festival_stream_viewers_empty') }}"
            data-checking="{{ __('app.festival_stream_status_checking') }}"
            data-checked-template="{{ __('app.festival_stream_status_checked', ['time' => ':time']) }}"
        >
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div @class(['rounded-2xl border p-4 shadow-crm', 'border-emerald-200 bg-emerald-50/60' => $stream->is_enabled, 'border-stone-200 bg-white' => ! $stream->is_enabled])>
                    <span class="text-sm text-slate-600">{{ __('app.status') }}</span>
                    <strong @class(['mt-1 block text-lg', 'text-emerald-800' => $stream->is_enabled, 'text-slate-800' => ! $stream->is_enabled])>{{ $stream->is_enabled ? __('app.active') : __('app.inactive') }}</strong>
                </div>
                <div data-festival-stream-server-card @class(['rounded-2xl border p-4 shadow-crm', 'border-emerald-200 bg-emerald-50/60' => $serverOnline, 'border-rose-200 bg-rose-50/60' => ! $serverOnline])>
                    <span class="text-sm text-slate-600">{{ __('app.festival_stream_server') }}</span>
                    <strong data-festival-stream-server-value @class(['mt-1 block text-lg', 'text-emerald-800' => $serverOnline, 'text-rose-800' => ! $serverOnline])>{{ $serverOnline ? __('app.festival_stream_server_online') : __('app.festival_stream_status_unavailable') }}</strong>
                </div>
                <div data-festival-stream-obs-card @class(['rounded-2xl border p-4 shadow-crm', 'border-emerald-200 bg-emerald-50/60' => $publisherOnline, 'border-amber-200 bg-amber-50/60' => $serverOnline && ! $publisherOnline, 'border-rose-200 bg-rose-50/60' => ! $serverOnline])>
                    <span class="text-sm text-slate-600">{{ __('app.festival_stream_obs_status') }}</span>
                    <strong data-festival-stream-obs-value @class(['mt-1 block text-lg', 'text-emerald-800' => $publisherOnline, 'text-amber-800' => $serverOnline && ! $publisherOnline, 'text-rose-800' => ! $serverOnline])>{{ ! $serverOnline ? __('app.festival_stream_status_unavailable') : ($publisherOnline ? __('app.festival_stream_publisher_online') : __('app.festival_stream_publisher_offline')) }}</strong>
                    <p data-festival-stream-obs-details class="mt-1 text-xs text-slate-600">{{ $publisherOnline && $trackCodecs !== [] ? __('app.festival_stream_tracks', ['tracks' => implode(', ', $trackCodecs)]) : ($serverOnline ? __('app.festival_stream_waiting_for_obs_help') : '') }}</p>
                </div>
                <div data-festival-stream-viewers-card @class(['rounded-2xl border p-4 shadow-crm', 'border-sky-200 bg-sky-50/60' => $readerCount > 0, 'border-stone-200 bg-white' => $readerCount === 0])>
                    <span class="text-sm text-slate-600">{{ __('app.festival_stream_viewers') }}</span>
                    <strong data-festival-stream-viewers-value @class(['mt-1 block text-lg', 'text-sky-800' => $readerCount > 0, 'text-slate-800' => $readerCount === 0])>{{ $readerCount }}</strong>
                    <p data-festival-stream-viewers-details class="mt-1 text-xs text-slate-600">{{ $readerCount > 0 ? __('app.festival_stream_readers', ['count' => $readerCount]) : __('app.festival_stream_viewers_empty') }}</p>
                </div>
            </div>
            <p data-festival-stream-status-message class="mt-2 text-right text-xs text-slate-500" role="status" aria-live="polite">{{ __('app.festival_stream_status_checking') }}</p>
        </section>
    @endif

    @if ($stream)
        <nav class="flex gap-2 border-b border-stone-200" aria-label="{{ __('app.festival_stream_settings') }}">
            <a href="{{ route('dashboard.accounts.festivals.online-stream.edit', [$account, $edition]) }}" @class(['-mb-px border-b-2 px-3 py-2 text-sm font-semibold transition', 'border-brand-600 text-brand-700' => $activeStreamTab === 'settings', 'border-transparent text-slate-500 hover:text-slate-900' => $activeStreamTab !== 'settings'])>{{ __('app.festival_stream_configuration_tab') }}</a>
            @if ($stream->is_enabled)
                <a href="{{ route('dashboard.accounts.festivals.online-stream.edit', [$account, $edition]).'?tab=preview' }}" @class(['-mb-px border-b-2 px-3 py-2 text-sm font-semibold transition', 'border-brand-600 text-brand-700' => $activeStreamTab === 'preview', 'border-transparent text-slate-500 hover:text-slate-900' => $activeStreamTab !== 'preview'])>{{ __('app.festival_stream_preview_tab') }}</a>
            @endif
        </nav>
    @endif

    @if ($stream && $activeStreamTab === 'preview')
        <x-ui.panel class="max-w-6xl" data-festival-stream-staff-preview-tab>
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-slate-950">{{ __('app.festival_stream_preview_title') }}</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ __('app.festival_stream_preview_copy') }}</p>
                </div>
                <x-ui.button :href="route('dashboard.accounts.festivals.online-stream.preview', [$account, $edition])" variant="secondary" target="_blank" rel="noopener">
                    <x-ui.icon name="maximize-2" class="h-4 w-4" />
                    {{ __('app.festival_stream_preview_fullscreen') }}
                </x-ui.button>
            </div>
            <div class="overflow-hidden rounded-2xl border border-slate-800 bg-black shadow-2xl">
                <iframe src="{{ route('dashboard.accounts.festivals.online-stream.preview', [$account, $edition]) }}" title="{{ __('app.festival_stream_preview_frame_title') }}" allow="autoplay; fullscreen" allowfullscreen class="aspect-video w-full bg-black"></iframe>
            </div>
        </x-ui.panel>
    @else
    @if ($stream)
        <x-ui.panel class="max-w-4xl border-brand-200 bg-brand-50/40" data-festival-stream-staff-preview>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-slate-950">{{ __('app.festival_stream_preview_title') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">{{ __('app.festival_stream_preview_copy') }}</p>
                    @unless ($stream->is_enabled)
                        <p class="mt-2 text-sm font-semibold text-amber-700">{{ __('app.festival_stream_preview_requires_enabled') }}</p>
                    @endunless
                </div>
                @if ($stream->is_enabled)
                    <x-ui.button :href="route('dashboard.accounts.festivals.online-stream.edit', [$account, $edition]).'?tab=preview'">
                        <x-ui.icon name="play" class="h-4 w-4" />
                        {{ __('app.festival_stream_preview_open') }}
                    </x-ui.button>
                @endif
            </div>
        </x-ui.panel>
    @endif

    <x-ui.panel data-festival-stream-configuration>
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

                <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5">
                    <h2 class="text-base font-semibold text-sky-950">{{ __('app.festival_stream_obs_quick_setup') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-sky-900">{{ __('app.festival_stream_obs_quick_setup_copy') }}</p>
                    <ul class="mt-3 grid gap-2 text-sm text-sky-950 sm:grid-cols-2">
                        <li class="rounded-xl bg-white/80 px-3 py-2">{{ __('app.festival_stream_obs_video_settings') }}</li>
                        <li class="rounded-xl bg-white/80 px-3 py-2">{{ __('app.festival_stream_obs_output_settings') }}</li>
                        <li class="rounded-xl bg-white/80 px-3 py-2">{{ __('app.festival_stream_obs_audio_settings') }}</li>
                        <li class="rounded-xl bg-white/80 px-3 py-2">{{ __('app.festival_stream_obs_start_help') }}</li>
                    </ul>
                    <a href="{{ route('help.show', 'festivals').'#help-section-festivals-online-streaming' }}" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-brand-700 hover:text-brand-600">
                        {{ __('app.festival_stream_full_help') }}
                        <x-ui.icon name="arrow-up-right" class="h-4 w-4" />
                    </a>
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
    @endif
</x-festivals.staff.workspace>
@endsection
