@extends('layouts.festival-portal')

@section('title', __('app.festival_online_stream'))

@section('content')
<main class="min-h-screen bg-slate-950 px-4 py-6 text-white sm:px-6">
    <div class="mx-auto max-w-6xl">
        <div class="mb-5 flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-brand-300">{{ __('app.festival_online_stream') }}</p>
                @if ($isStaffPreview)
                    <span data-festival-stream-staff-preview class="mt-2 inline-flex rounded-full border border-sky-300/30 bg-sky-400/10 px-2.5 py-1 text-xs font-semibold text-sky-200">{{ __('app.festival_stream_preview_badge') }}</span>
                @endif
                <h1 class="mt-1 text-2xl font-semibold">{{ $stream->edition->title }}</h1>
            </div>
        </div>
        <div class="overflow-hidden rounded-2xl border border-white/10 bg-black shadow-2xl">
            <video data-festival-stream-player data-playlist-url="{{ $playlistUrl }}" data-heartbeat-url="{{ $heartbeatUrl }}" controls autoplay playsinline class="aspect-video w-full bg-black"></video>
        </div>
        <div data-festival-stream-error class="mt-4 hidden rounded-xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-100">{{ __('app.festival_stream_unavailable') }}</div>
        <p class="mt-4 text-sm text-slate-400">{{ $isStaffPreview ? __('app.festival_stream_preview_player_help') : __('app.festival_stream_sharing_limit') }}</p>
    </div>
</main>
@endsection
