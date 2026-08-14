@extends('layouts.app')

@section('title', __('app.festival_stream_settings').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_stream_settings')" :copy="__('app.festival_stream_settings_copy')">
        <x-slot:actions>
            @if ($stream)
                <form method="POST" action="{{ route('dashboard.accounts.festivals.online-stream.start', [$account, $edition]) }}">
                    @csrf
                    @method('PATCH')
                    <x-ui.button type="submit" variant="success" :disabled="! $stream->is_enabled || $stream->playback_override === \App\Enums\FestivalStreamOverride::Open">
                        <x-ui.icon name="play" class="h-4 w-4" />
                        {{ __('app.festival_stream_start') }}
                    </x-ui.button>
                </form>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.online-stream.stop', [$account, $edition]) }}">
                    @csrf
                    @method('PATCH')
                    <x-ui.button type="submit" variant="danger" :disabled="$stream->playback_override === \App\Enums\FestivalStreamOverride::Closed">
                        <x-ui.icon name="square" class="h-4 w-4" />
                        {{ __('app.festival_stream_stop') }}
                    </x-ui.button>
                </form>
            @endif
            <x-ui.button :href="route('help.show', 'festivals').'#help-section-festivals-online-streaming'" variant="secondary" target="_blank" rel="noopener">
                <x-ui.icon name="circle-help" class="h-4 w-4" />
                {{ __('app.festival_stream_full_help') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @error('stream')
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">{{ $message }}</div>
    @enderror

    @if (! $stream)
        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm text-sky-950">
            <strong>{{ __('app.festival_stream_not_configured') }}</strong>
            <p class="mt-1">{{ __('app.festival_stream_settings_copy') }}</p>
        </div>
    @else
        @php
            $isMediaMtx = $stream->provider === \App\Enums\FestivalStreamProvider::MediaMtx;
            $serverOnline = $streamStatus !== null;
            $publisherOnline = $streamStatus['publisher_online'] ?? false;
            $readerCount = $isMediaMtx ? ($streamStatus['readers'] ?? 0) : $activeStreamConnections;
            $trackCodecs = $streamStatus['tracks'] ?? [];
        @endphp
        <section
            data-festival-stream-status
            data-status-url="{{ route('dashboard.accounts.festivals.online-stream.status', [$account, $edition]) }}"
            data-provider-mediamtx="{{ __('app.festival_stream_provider_mediamtx') }}"
            data-provider-youtube="{{ __('app.festival_stream_provider_youtube') }}"
            data-obs-status-label="{{ __('app.festival_stream_obs_status') }}"
            data-youtube-status-label="{{ __('app.festival_stream_youtube_status') }}"
            data-hls-viewers-label="{{ __('app.festival_stream_viewers') }}"
            data-youtube-connections-label="{{ __('app.festival_stream_youtube_connections_label') }}"
            data-server-online="{{ __('app.festival_stream_server_online') }}"
            data-server-unavailable="{{ __('app.festival_stream_status_unavailable') }}"
            data-obs-online="{{ __('app.festival_stream_publisher_online') }}"
            data-obs-offline="{{ __('app.festival_stream_publisher_offline') }}"
            data-obs-connected-template="{{ __('app.festival_stream_connected_since', ['time' => ':time']) }}"
            data-obs-tracks-template="{{ __('app.festival_stream_tracks', ['tracks' => ':tracks']) }}"
            data-obs-waiting="{{ __('app.festival_stream_waiting_for_obs_help') }}"
            data-viewers-template="{{ __('app.festival_stream_readers', ['count' => ':count']) }}"
            data-viewers-empty="{{ __('app.festival_stream_viewers_empty') }}"
            data-youtube-configured="{{ __('app.festival_stream_youtube_configured') }}"
            data-youtube-unavailable="{{ __('app.festival_stream_youtube_not_configured') }}"
            data-youtube-status-help="{{ __('app.festival_stream_youtube_status_help') }}"
            data-youtube-connections-template="{{ __('app.festival_stream_youtube_connections', ['count' => ':count']) }}"
            data-youtube-connections-empty="{{ __('app.festival_stream_youtube_connections_empty') }}"
            data-checking="{{ __('app.festival_stream_status_checking') }}"
            data-checked-template="{{ __('app.festival_stream_status_checked', ['time' => ':time']) }}"
        >
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div @class(['rounded-2xl border p-4 shadow-crm', 'border-emerald-200 bg-emerald-50/60' => $stream->is_enabled, 'border-stone-200 bg-white' => ! $stream->is_enabled])>
                    <span class="text-sm text-slate-600">{{ __('app.status') }}</span>
                    <strong @class(['mt-1 block text-lg', 'text-emerald-800' => $stream->is_enabled, 'text-slate-800' => ! $stream->is_enabled])>{{ $stream->is_enabled ? __('app.active') : __('app.inactive') }}</strong>
                </div>
                <div data-festival-stream-provider-card class="rounded-2xl border border-sky-200 bg-sky-50/60 p-4 shadow-crm">
                    <span class="text-sm text-slate-600">{{ __('app.festival_stream_active_source') }}</span>
                    <strong data-festival-stream-provider-value class="mt-1 block text-lg text-sky-800">{{ $isMediaMtx ? __('app.festival_stream_provider_mediamtx') : __('app.festival_stream_provider_youtube') }}</strong>
                    <p data-festival-stream-provider-details class="mt-1 text-xs text-slate-600">{{ $isMediaMtx ? ($serverOnline ? __('app.festival_stream_server_online') : __('app.festival_stream_status_unavailable')) : __('app.festival_stream_youtube_status_help') }}</p>
                </div>
                <div data-festival-stream-health-card @class(['rounded-2xl border p-4 shadow-crm', 'border-emerald-200 bg-emerald-50/60' => $isMediaMtx ? $publisherOnline : filled($stream->youtube_video_id), 'border-amber-200 bg-amber-50/60' => $isMediaMtx && $serverOnline && ! $publisherOnline, 'border-rose-200 bg-rose-50/60' => ($isMediaMtx && ! $serverOnline) || (! $isMediaMtx && blank($stream->youtube_video_id))])>
                    <span data-festival-stream-health-label class="text-sm text-slate-600">{{ $isMediaMtx ? __('app.festival_stream_obs_status') : __('app.festival_stream_youtube_status') }}</span>
                    <strong data-festival-stream-health-value @class(['mt-1 block text-lg', 'text-emerald-800' => $isMediaMtx ? $publisherOnline : filled($stream->youtube_video_id), 'text-amber-800' => $isMediaMtx && $serverOnline && ! $publisherOnline, 'text-rose-800' => ($isMediaMtx && ! $serverOnline) || (! $isMediaMtx && blank($stream->youtube_video_id))])>{{ $isMediaMtx ? (! $serverOnline ? __('app.festival_stream_status_unavailable') : ($publisherOnline ? __('app.festival_stream_publisher_online') : __('app.festival_stream_publisher_offline'))) : (filled($stream->youtube_video_id) ? __('app.festival_stream_youtube_configured') : __('app.festival_stream_youtube_not_configured')) }}</strong>
                    <p data-festival-stream-health-details class="mt-1 text-xs text-slate-600">{{ $isMediaMtx ? ($publisherOnline && $trackCodecs !== [] ? __('app.festival_stream_tracks', ['tracks' => implode(', ', $trackCodecs)]) : ($serverOnline ? __('app.festival_stream_waiting_for_obs_help') : '')) : __('app.festival_stream_youtube_status_help') }}</p>
                </div>
                <div data-festival-stream-viewers-card @class(['rounded-2xl border p-4 shadow-crm', 'border-sky-200 bg-sky-50/60' => $readerCount > 0, 'border-stone-200 bg-white' => $readerCount === 0])>
                    <span data-festival-stream-viewers-label class="text-sm text-slate-600">{{ $isMediaMtx ? __('app.festival_stream_viewers') : __('app.festival_stream_youtube_connections_label') }}</span>
                    <strong data-festival-stream-viewers-value @class(['mt-1 block text-lg', 'text-sky-800' => $readerCount > 0, 'text-slate-800' => $readerCount === 0])>{{ $readerCount }}</strong>
                    <p data-festival-stream-viewers-details class="mt-1 text-xs text-slate-600">{{ $readerCount > 0 ? ($isMediaMtx ? __('app.festival_stream_readers', ['count' => $readerCount]) : __('app.festival_stream_youtube_connections', ['count' => $readerCount])) : ($isMediaMtx ? __('app.festival_stream_viewers_empty') : __('app.festival_stream_youtube_connections_empty')) }}</p>
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
                <iframe src="{{ route('dashboard.accounts.festivals.online-stream.preview', [$account, $edition]) }}" title="{{ __('app.festival_stream_preview_frame_title') }}" allow="autoplay; fullscreen" allowfullscreen scrolling="no" class="block aspect-video w-full border-0 bg-black"></iframe>
            </div>
        </x-ui.panel>
    @else
    <x-ui.panel data-festival-stream-configuration>
        @php
            $selectedProvider = old('provider', $stream?->provider?->value ?? \App\Enums\FestivalStreamProvider::MediaMtx->value);
            $streamIsOpen = $stream?->playback_override === \App\Enums\FestivalStreamOverride::Open;
            $youtubeUrl = old('youtube_url', \App\Support\Festivals\FestivalYouTubeVideo::watchUrl($stream?->youtube_video_id));
        @endphp
        <form method="POST" action="{{ route('dashboard.accounts.festivals.online-stream.update', [$account, $edition]) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <fieldset>
                <legend class="crm-label">{{ __('app.festival_stream_source') }}</legend>
                <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_stream_source_help') }}</p>
                @if($streamIsOpen)
                    <input type="hidden" name="provider" value="{{ $stream->provider->value }}">
                @endif
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    @foreach(\App\Enums\FestivalStreamProvider::cases() as $provider)
                        <label @class(['rounded-2xl border p-5 transition has-checked:border-brand-300 has-checked:bg-brand-50/50 has-checked:ring-1 has-checked:ring-brand-200', 'border-brand-300 bg-brand-50/50' => $selectedProvider === $provider->value, 'border-slate-200 bg-white' => $selectedProvider !== $provider->value, 'cursor-not-allowed opacity-70' => $streamIsOpen, 'cursor-pointer hover:border-brand-200' => ! $streamIsOpen])>
                            <span class="flex items-start gap-3">
                                <input type="radio" name="provider" value="{{ $provider->value }}" class="crm-radio mt-1" @checked($selectedProvider === $provider->value) @disabled($streamIsOpen)>
                                <span>
                                    <strong class="block text-slate-950">{{ __($provider === \App\Enums\FestivalStreamProvider::MediaMtx ? 'app.festival_stream_provider_mediamtx' : 'app.festival_stream_provider_youtube') }}</strong>
                                    <span class="mt-1 block text-sm leading-6 text-slate-600">{{ __($provider === \App\Enums\FestivalStreamProvider::MediaMtx ? 'app.festival_stream_provider_mediamtx_help' : 'app.festival_stream_provider_youtube_help') }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('provider') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                @if($streamIsOpen)
                    <p class="mt-2 text-sm font-medium text-amber-700">{{ __('app.festival_stream_stop_before_switching') }}</p>
                @endif
            </fieldset>

            <input type="hidden" name="is_enabled" value="0">
            @if ($stream)
                <label class="flex items-start gap-3 rounded-2xl border border-slate-200 p-4 text-sm text-slate-700">
                    <input type="checkbox" name="is_enabled" value="1" class="crm-checkbox mt-0.5" @checked(old('is_enabled', $stream->is_enabled))>
                    <span><strong class="block text-slate-950">{{ __('app.festival_stream_enabled') }}</strong>{{ __('app.festival_online_ticket_requires_stream') }}</span>
                </label>
                @error('is_enabled') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
            @endif

            <input type="hidden" name="rotate_publisher_token" value="0">
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-base font-semibold text-slate-950">{{ __('app.festival_stream_youtube_configuration') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_stream_youtube_configuration_help') }}</p>
                <label class="mt-4 block">
                    <span class="crm-label">{{ __('app.festival_stream_youtube_url') }}</span>
                    <input type="url" name="youtube_url" value="{{ $youtubeUrl }}" placeholder="https://www.youtube.com/watch?v=…" class="crm-field mt-1" @readonly($streamIsOpen && $stream?->provider === \App\Enums\FestivalStreamProvider::YouTube)>
                </label>
                @error('youtube_url') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                    <strong class="block">{{ __('app.festival_stream_youtube_warning_title') }}</strong>
                    {{ __('app.festival_stream_youtube_warning') }}
                </div>
            </div>

            @if ($stream)
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                    <h2 class="mb-4 text-base font-semibold text-slate-950">{{ __('app.festival_stream_mediamtx_configuration') }}</h2>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div><span class="crm-label">{{ __('app.festival_stream_obs_server') }}</span><code class="mt-1 block break-all rounded-lg bg-white p-3 text-xs">{{ config('services.festival_stream.obs_server') ?: __('app.not_configured') }}</code></div>
                        <div><span class="crm-label">{{ __('app.festival_stream_obs_key') }}</span><code class="mt-1 block break-all rounded-lg bg-white p-3 text-xs">{{ $stream->path }}?token={{ $stream->publisher_token_encrypted }}</code></div>
                    </div>
                    <p class="mt-3 text-xs text-slate-600">{{ __('app.festival_stream_obs_private_help') }}</p>
                    <label @class(['mt-4 flex items-center gap-2 text-sm text-slate-700', 'cursor-not-allowed opacity-60' => $streamIsOpen && $stream->provider === \App\Enums\FestivalStreamProvider::MediaMtx])><input type="checkbox" name="rotate_publisher_token" value="1" class="crm-checkbox" @disabled($streamIsOpen && $stream->provider === \App\Enums\FestivalStreamProvider::MediaMtx)>{{ __('app.festival_stream_rotate_key') }}</label>
                    @error('rotate_publisher_token') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
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
