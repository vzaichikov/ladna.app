@extends('layouts.app')

@section('title', __('app.qr_links_title').' - '.$account->name)

@section('content')
    <div>
        <h1 class="crm-page-title">{{ __('app.qr_links_title') }}</h1>
        <p class="crm-page-copy">{{ __('app.qr_links_copy') }}</p>
    </div>

    <div class="mt-6 grid max-w-6xl gap-6 lg:grid-cols-2">
        @foreach ($printableLinks as $printableLink)
            <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm" data-print-section>
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between" data-print-screen-only>
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ $printableLink['title'] }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ $printableLink['description'] }}</p>
                    </div>
                    <x-ui.button type="button" variant="secondary" data-print-button>
                        <x-ui.icon name="printer" class="h-4 w-4" />
                        {{ __('app.print') }}
                    </x-ui.button>
                </div>

                <div class="mt-6 grid gap-6 sm:grid-cols-[240px_1fr] sm:items-center" data-qr-screen-content>
                    <div class="flex aspect-square items-center justify-center overflow-hidden rounded-xl border border-stone-200 bg-white p-4 [&>svg]:h-auto [&>svg]:max-w-full">
                        {!! $printableLink['qr_svg'] !!}
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-3">
                            <img src="{{ $account->logoUrl() }}" alt="" class="h-12 w-12 rounded-lg object-contain ring-1 ring-stone-200">
                            <div>
                                <div class="text-base font-semibold text-slate-950">{{ $account->name }}</div>
                                <div class="text-sm text-slate-500">{{ $printableLink['subject'] }}</div>
                            </div>
                        </div>
                        <div class="mt-5">
                            <span class="crm-label">{{ __('app.public_url') }}</span>
                            <div class="mt-2 flex flex-col gap-2" data-copy-container>
                                <input value="{{ $printableLink['url'] }}" readonly class="crm-field font-mono text-xs" data-copy-source>
                                <x-ui.button type="button" variant="secondary" data-copy-button data-copy-success-label="{{ __('app.copied') }}">
                                    <x-ui.icon name="copy" class="h-4 w-4" />
                                    <span data-copy-label>{{ __('app.copy') }}</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hidden" data-qr-print-poster>
                    <header class="flex flex-col items-center text-center">
                        <img src="{{ $account->logoUrl() }}" alt="" class="h-20 w-20 object-contain">
                        <div class="mt-4 text-2xl font-semibold text-slate-950">{{ $account->name }}</div>
                        <div class="mt-1 text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $printableLink['subject'] }}</div>
                        @if ($printableLink['description'] !== $printableLink['subject'])
                            <div class="mt-2 text-base font-semibold text-slate-700">{{ $printableLink['description'] }}</div>
                        @endif
                    </header>
                    <div class="flex flex-1 flex-col items-center justify-center gap-8 text-center">
                        <div class="flex items-center justify-center rounded-[28px] border border-stone-200 bg-white p-8" data-qr-print-code>
                            {!! $printableLink['qr_svg'] !!}
                        </div>
                        <div class="max-w-[620px] break-all font-mono text-lg font-semibold leading-7 text-slate-900" data-qr-print-url>
                            {{ $printableLink['url'] }}
                        </div>
                    </div>
                    <x-ui.powered-footer />
                </div>
            </section>
        @endforeach

        <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm lg:col-span-2">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.public_links') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.public_links_copy') }}</p>
            </div>

            <div class="mt-5 grid gap-3">
                @foreach ($generalPublicLinks as $publicLink)
                    <div class="rounded-lg border border-stone-200 bg-slate-50 p-4" data-public-link-row>
                        <div class="text-sm font-semibold text-slate-950">{{ $publicLink['label'] }}</div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-ui.button :href="$publicLink['url']" variant="secondary" size="sm" target="_blank" rel="noopener">
                                <x-ui.icon name="external" class="h-4 w-4" />
                                {{ $publicLink['open_label'] }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="sm" data-copy-button data-copy-value="{{ $publicLink['url'] }}" data-copy-success-label="{{ __('app.copied') }}">
                                <x-ui.icon name="copy" class="h-4 w-4" />
                                <span data-copy-label>{{ __('app.copy_link') }}</span>
                            </x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>

            @forelse ($publicLinkLocations as $publicLinkLocation)
                <div class="mt-6 border-t border-stone-100 pt-6">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">{{ $publicLinkLocation['location']->name }}</h3>
                        @if ($publicLinkLocation['location']->address)
                            <p class="mt-1 text-sm leading-6 text-slate-500">{{ $publicLinkLocation['location']->address }}</p>
                        @endif
                    </div>

                    <div class="mt-4 grid gap-3">
                        @foreach ($publicLinkLocation['links'] as $publicLink)
                            <div class="flex flex-col gap-3 rounded-lg border border-stone-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between" data-public-link-row data-location-id="{{ $publicLinkLocation['location']->id }}">
                                <div class="flex min-w-0 items-center gap-3">
                                    <x-ui.icon :name="$publicLink['icon']" class="h-5 w-5 shrink-0 text-violet-crm-600" />
                                    <span class="truncate text-sm font-semibold text-slate-950">{{ $publicLink['label'] }}</span>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.button :href="$publicLink['url']" variant="secondary" size="sm" target="_blank" rel="noopener">
                                        <x-ui.icon name="external" class="h-4 w-4" />
                                        {{ __('app.open') }}
                                    </x-ui.button>
                                    <x-ui.button type="button" variant="ghost" size="sm" data-copy-button data-copy-value="{{ $publicLink['url'] }}" data-copy-success-label="{{ __('app.copied') }}">
                                        <x-ui.icon name="copy" class="h-4 w-4" />
                                        <span data-copy-label>{{ __('app.copy_link') }}</span>
                                    </x-ui.button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="mt-6 rounded-lg border border-dashed border-stone-200 bg-stone-50 p-5">
                    <p class="text-sm leading-6 text-slate-500">{{ __('app.public_links_empty') }}</p>
                    <x-ui.button :href="route('dashboard.accounts.locations.index', $account)" variant="secondary" size="sm" class="mt-4">
                        <x-ui.icon name="locations" class="h-4 w-4" />
                        {{ __('app.locations') }}
                    </x-ui.button>
                </div>
            @endforelse
        </section>
    </div>
@endsection
