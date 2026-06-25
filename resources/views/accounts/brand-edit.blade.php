@extends('layouts.app')

@section('title', __('app.my_brand').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.my_brand') }}</h1>
            <p class="crm-page-copy">{{ __('app.business_details_copy') }}</p>
        </div>
    </div>

    <nav class="mt-6 flex gap-2 overflow-x-auto border-b border-slate-200" aria-label="{{ __('app.my_brand') }}">
        <a
            href="{{ route('dashboard.accounts.brand.edit', $account) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'business' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.business_details') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.brand.edit', [$account, 'tab' => 'formats']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'formats' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.studio_class_formats') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.brand.edit', [$account, 'tab' => 'opening_hours']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'opening_hours' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.opening_hours') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.brand.edit', [$account, 'tab' => 'rules']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'rules' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.studio_rules') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.brand.edit', [$account, 'tab' => 'qr']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'qr' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.login_qr_codes_and_links') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.brand.edit', [$account, 'tab' => 'api']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'api' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.api') }}
        </a>
    </nav>

    @if ($activeTab === 'qr')
        <div class="mt-6 grid max-w-6xl gap-6 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
            <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm" data-print-section>
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between" data-print-screen-only>
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.login_qr_codes') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.login_qr_codes_copy') }}</p>
                    </div>
                    <x-ui.button type="button" variant="secondary" data-print-button>
                        <x-ui.icon name="printer" class="h-4 w-4" />
                        {{ __('app.print') }}
                    </x-ui.button>
                </div>

                <div class="mt-6 grid gap-6 sm:grid-cols-[240px_1fr] sm:items-center" data-qr-screen-content>
                    <div class="flex aspect-square items-center justify-center rounded-xl border border-stone-200 bg-white p-4">
                        {!! $customerLoginQrSvg !!}
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-3">
                            <img src="{{ $account->logoUrl() }}" alt="" class="h-12 w-12 rounded-lg object-contain ring-1 ring-stone-200">
                            <div>
                                <div class="text-base font-semibold text-slate-950">{{ $account->name }}</div>
                                <div class="text-sm text-slate-500">{{ __('app.customer_login') }}</div>
                            </div>
                        </div>
                        <label class="mt-5 block">
                            <span class="crm-label">{{ __('app.login_url') }}</span>
                            <input value="{{ $customerLoginUrl }}" readonly class="crm-field font-mono text-xs">
                        </label>
                    </div>
                </div>

                <div class="hidden" data-qr-print-poster>
                    <header class="flex flex-col items-center text-center">
                        <img src="{{ $account->logoUrl() }}" alt="" class="h-20 w-20 object-contain">
                        <div class="mt-4 text-2xl font-semibold text-slate-950">{{ $account->name }}</div>
                        <div class="mt-1 text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('app.customer_login') }}</div>
                    </header>
                    <div class="flex flex-1 flex-col items-center justify-center gap-8 text-center">
                        <div class="flex items-center justify-center rounded-[28px] border border-stone-200 bg-white p-8" data-qr-print-code>
                            {!! $customerLoginQrSvg !!}
                        </div>
                        <div class="max-w-[620px] break-all font-mono text-lg font-semibold leading-7 text-slate-900" data-qr-print-url>
                            {{ $customerLoginUrl }}
                        </div>
                    </div>
                    <x-ui.powered-footer />
                </div>
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.public_links') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.public_links_copy') }}</p>
                </div>

                <div class="mt-5 rounded-lg border border-stone-200 bg-slate-50 p-4">
                    <div class="text-sm font-semibold text-slate-950">{{ __('app.studio_rules') }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.button :href="route('public.studio-rules', $account->slug)" variant="secondary" size="sm" target="_blank" rel="noopener">
                            <x-ui.icon name="external" class="h-4 w-4" />
                            {{ __('app.open_public_studio_rules') }}
                        </x-ui.button>
                    </div>
                </div>

                @forelse ($publicLinkLocations as $publicLinkLocation)
                    <div class="mt-5 border-t border-stone-100 pt-5 first:border-t-0 first:pt-0">
                        <div class="flex flex-col gap-1">
                            <h3 class="text-base font-semibold text-slate-950">{{ $publicLinkLocation['location']->name }}</h3>
                            @if ($publicLinkLocation['location']->address)
                                <p class="text-sm leading-6 text-slate-500">{{ $publicLinkLocation['location']->address }}</p>
                            @endif
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ui.button :href="$publicLinkLocation['schedule_url']" variant="secondary" size="sm" target="_blank" rel="noopener">
                                <x-ui.icon name="schedule" class="h-4 w-4" />
                                {{ __('app.public_schedule') }}
                            </x-ui.button>
                            <x-ui.button :href="$publicLinkLocation['price_url']" variant="secondary" size="sm" target="_blank" rel="noopener">
                                <x-ui.icon name="class-pass-plans" class="h-4 w-4" />
                                {{ __('app.public_price') }}
                            </x-ui.button>
                            <x-ui.button :href="$publicLinkLocation['schedule_embed_url']" variant="ghost" size="sm" target="_blank" rel="noopener">
                                <x-ui.icon name="external" class="h-4 w-4" />
                                {{ __('app.public_schedule_embed') }}
                            </x-ui.button>
                            <x-ui.button :href="$publicLinkLocation['price_embed_url']" variant="ghost" size="sm" target="_blank" rel="noopener">
                                <x-ui.icon name="external" class="h-4 w-4" />
                                {{ __('app.public_price_embed') }}
                            </x-ui.button>
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
    @elseif ($activeTab === 'formats')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'formats']) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.schedule-format-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'opening_hours')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'opening_hours']) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.opening-hours-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'rules')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'rules']) }}" class="mt-6 max-w-4xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.studio-rules-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'api')
        @include('accounts.api-tokens', ['apiTokens' => $apiTokens])
    @else
        <form method="POST" action="{{ route('dashboard.accounts.update', $account) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')
            <input type="hidden" name="brand_tab" value="business">

            @include('accounts.form-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @endif
@endsection
