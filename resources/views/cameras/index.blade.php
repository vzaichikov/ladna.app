@extends('layouts.app')

@section('title', __('app.cameras').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.cameras') }}</h1>
            <p class="crm-page-copy">{{ __('app.cameras_copy') }}</p>
        </div>
    </div>

    @unless ($gatewayConfigured)
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950">
            {{ __('app.rtsp_camera_gateway_unavailable') }}
        </div>
    @endunless

    <section class="mt-6 grid gap-5 xl:grid-cols-2">
        @forelse ($streams as $stream)
            @php($camera = $stream['camera'])
            <article class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-crm">
                <div class="border-b border-stone-100 px-5 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">{{ $camera->name }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ $camera->location?->name }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-2">
                            <span class="crm-status-active">{{ __('app.live') }}</span>
                            <span class="crm-status-muted">{{ $stream['type'] === 'service_room' ? __('app.service_room') : __('app.room') }}</span>
                        </div>
                    </div>
                </div>

                <div class="aspect-video bg-slate-950">
                    @if ($gatewayConfigured && $stream['available'])
                        <iframe
                            src="{{ $stream['playerUrl'] }}"
                            title="{{ $camera->name }}"
                            allow="autoplay; fullscreen; picture-in-picture"
                            class="h-full w-full border-0"
                            loading="lazy"
                        ></iframe>
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
