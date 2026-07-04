@extends('layouts.app')

@section('title', __('app.cameras').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.cameras') }}</h1>
            <p class="crm-page-copy">{{ __('app.cameras_copy') }}</p>
        </div>
    </div>

    @unless ($ffmpegAvailable)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950">
            {{ __('app.rtsp_camera_ffmpeg_unavailable') }}
        </div>
    @endunless

    <section class="mt-6 grid gap-5 xl:grid-cols-2">
        @forelse ($rooms as $room)
            <article class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-crm">
                <div class="border-b border-stone-100 px-5 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">{{ $room->name }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ $room->location?->name }}</p>
                        </div>
                        <span class="crm-status-active">{{ __('app.live') }}</span>
                    </div>
                </div>

                <div class="aspect-video bg-slate-950">
                    @if ($ffmpegAvailable)
                        <img
                            src="{{ route('dashboard.accounts.cameras.stream', [$account, $room]) }}"
                            alt="{{ $room->name }}"
                            class="h-full w-full object-contain"
                        >
                    @else
                        <div class="flex h-full items-center justify-center px-6 text-center text-sm font-semibold text-white">
                            {{ __('app.rtsp_camera_stream_unavailable') }}
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.no_enabled_cameras')" icon="video" class="xl:col-span-2" />
        @endforelse
    </section>
@endsection
