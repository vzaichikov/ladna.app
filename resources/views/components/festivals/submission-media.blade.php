@props([
    'account',
    'submission',
])

@php
    $playbackKind = $submission->playbackKind();
    $viewUrl = route('dashboard.accounts.festivals.submissions.view', [$account, $submission]);
    $downloadUrl = route('dashboard.accounts.festivals.submissions.download', [$account, $submission]);
@endphp

<div {{ $attributes->class(['min-w-0 space-y-2']) }}>
    @if ($playbackKind === 'audio')
        <audio controls preload="none" class="block w-full" aria-label="{{ __('app.festival_listen_to_file', ['file' => $submission->original_name]) }}">
            <source src="{{ $viewUrl }}" type="{{ $submission->mime_type }}">
            {{ __('app.festival_media_playback_unavailable') }}
        </audio>
    @elseif ($playbackKind === 'video')
        <video controls playsinline preload="none" class="aspect-video max-h-96 w-full rounded-xl bg-black object-contain" aria-label="{{ __('app.festival_watch_file', ['file' => $submission->original_name]) }}">
            <source src="{{ $viewUrl }}" type="{{ $submission->mime_type }}">
            {{ __('app.festival_media_playback_unavailable') }}
        </video>
    @endif

    <div class="flex min-w-0 flex-wrap items-center justify-between gap-2 text-xs">
        <span class="min-w-0 break-all text-slate-600">{{ $submission->original_name }}</span>
        <a href="{{ $downloadUrl }}" class="shrink-0 font-semibold text-brand-700 hover:text-brand-800">{{ __('app.download') }}</a>
    </div>
</div>
