@extends('layouts.app')

@section('title', __('app.my_brand').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.my_brand') }}</h1>
            <p class="crm-page-copy">{{ __('app.business_details_copy') }}</p>
        </div>
    </div>

    <nav class="mt-6 flex flex-wrap gap-2 border-b border-slate-200" aria-label="{{ __('app.my_brand') }}">
        <a
            href="{{ route('dashboard.accounts.general-settings.edit', $account) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'business' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.business_details') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'formats']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'formats' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.studio_class_formats') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'opening_hours']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'opening_hours' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.opening_hours') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'rules']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'rules' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.studio_rules') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'pass_rules']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'pass_rules' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.class_passes_and_classes') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'schedule_view']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'schedule_view' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.public_schedule_view') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'qr']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'qr' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.login_qr_codes_and_links') }}
        </a>
        @if ($account->customerNotificationsEnabled())
            <a
                href="{{ route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'customer_notifications']) }}"
                class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'customer_notifications' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
            >
                {{ __('app.customer_notifications') }}
            </a>
        @endif
        <a
            href="{{ route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'api' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.api') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'ai']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'ai' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.ai_and_telegram') }}
        </a>
    </nav>

    @if ($activeTab === 'qr')
        <div class="mt-6 grid max-w-6xl gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm" data-print-section>
                <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between" data-print-screen-only>
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.studio_public_landing_qr') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.studio_public_landing_qr_copy') }}</p>
                    </div>
                    <x-ui.button type="button" variant="secondary" data-print-button>
                        <x-ui.icon name="printer" class="h-4 w-4" />
                        {{ __('app.print') }}
                    </x-ui.button>
                </div>

                <div class="mt-6 grid gap-6 sm:grid-cols-[240px_1fr] sm:items-center" data-qr-screen-content>
                    <div class="flex aspect-square items-center justify-center rounded-xl border border-stone-200 bg-white p-4">
                        {!! $studioLandingQrSvg !!}
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-3">
                            <img src="{{ $account->logoUrl() }}" alt="" class="h-12 w-12 rounded-lg object-contain ring-1 ring-stone-200">
                            <div>
                                <div class="text-base font-semibold text-slate-950">{{ $account->name }}</div>
                                <div class="text-sm text-slate-500">{{ __('app.studio_public_landing') }}</div>
                            </div>
                        </div>
                        <div class="mt-5">
                            <span class="crm-label">{{ __('app.public_url') }}</span>
                            <div class="mt-2 flex flex-col gap-2" data-copy-container>
                                <input value="{{ $studioLandingUrl }}" readonly class="crm-field font-mono text-xs" data-copy-source>
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
                        <div class="mt-1 text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('app.studio_public_landing') }}</div>
                    </header>
                    <div class="flex flex-1 flex-col items-center justify-center gap-8 text-center">
                        <div class="flex items-center justify-center rounded-[28px] border border-stone-200 bg-white p-8" data-qr-print-code>
                            {!! $studioLandingQrSvg !!}
                        </div>
                        <div class="max-w-[620px] break-all font-mono text-lg font-semibold leading-7 text-slate-900" data-qr-print-url>
                            {{ $studioLandingUrl }}
                        </div>
                    </div>
                    <x-ui.powered-footer />
                </div>
            </section>

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
                        <div class="mt-5">
                            <span class="crm-label">{{ __('app.login_url') }}</span>
                            <div class="mt-2 flex flex-col gap-2" data-copy-container>
                                <input value="{{ $customerLoginUrl }}" readonly class="crm-field font-mono text-xs" data-copy-source>
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

            @foreach ($publicLinkLocations as $publicLinkLocation)
                @foreach ($publicLinkLocation['printable_links'] as $printableLink)
                    <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm" data-print-section>
                        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between" data-print-screen-only>
                            <div>
                                <h2 class="text-lg font-semibold text-slate-950">{{ __($printableLink['label_key']) }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-500">{{ $publicLinkLocation['location']->name }}</p>
                            </div>
                            <x-ui.button type="button" variant="secondary" data-print-button>
                                <x-ui.icon name="printer" class="h-4 w-4" />
                                {{ __('app.print') }}
                            </x-ui.button>
                        </div>

                        <div class="mt-6 grid gap-6 sm:grid-cols-[240px_1fr] sm:items-center" data-qr-screen-content>
                            <div class="flex aspect-square items-center justify-center rounded-xl border border-stone-200 bg-white p-4">
                                {!! $printableLink['qr_svg'] !!}
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $account->logoUrl() }}" alt="" class="h-12 w-12 rounded-lg object-contain ring-1 ring-stone-200">
                                    <div>
                                        <div class="text-base font-semibold text-slate-950">{{ $account->name }}</div>
                                        <div class="text-sm text-slate-500">{{ __($printableLink['label_key']) }} · {{ $publicLinkLocation['location']->name }}</div>
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
                                <div class="mt-1 text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __($printableLink['label_key']) }}</div>
                                <div class="mt-2 text-base font-semibold text-slate-700">{{ $publicLinkLocation['location']->name }}</div>
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
            @endforeach

            <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm lg:col-span-2">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.public_links') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.public_links_copy') }}</p>
                </div>

                <div class="mt-5 rounded-lg border border-stone-200 bg-slate-50 p-4">
                    <div class="text-sm font-semibold text-slate-950">{{ __('app.studio_public_landing') }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.button :href="$studioLandingUrl" variant="secondary" size="sm" target="_blank" rel="noopener">
                            <x-ui.icon name="external" class="h-4 w-4" />
                            {{ __('app.open_public_studio_landing') }}
                        </x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" data-copy-button data-copy-value="{{ $studioLandingUrl }}" data-copy-success-label="{{ __('app.copied') }}">
                            <x-ui.icon name="copy" class="h-4 w-4" />
                            <span data-copy-label>{{ __('app.copy_link') }}</span>
                        </x-ui.button>
                    </div>
                </div>

                <div class="mt-5 rounded-lg border border-stone-200 bg-slate-50 p-4">
                    <div class="text-sm font-semibold text-slate-950">{{ __('app.studio_rules') }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.button :href="route('public.studio-rules', $account->slug)" variant="secondary" size="sm" target="_blank" rel="noopener">
                            <x-ui.icon name="external" class="h-4 w-4" />
                            {{ __('app.open_public_studio_rules') }}
                        </x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" data-copy-button data-copy-value="{{ route('public.studio-rules', $account->slug) }}" data-copy-success-label="{{ __('app.copied') }}">
                            <x-ui.icon name="copy" class="h-4 w-4" />
                            <span data-copy-label>{{ __('app.copy_link') }}</span>
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
                            <x-ui.button type="button" variant="ghost" size="sm" data-copy-button data-copy-value="{{ $publicLinkLocation['schedule_url'] }}" data-copy-success-label="{{ __('app.copied') }}">
                                <x-ui.icon name="copy" class="h-4 w-4" />
                                <span data-copy-label>{{ __('app.copy_link') }}</span>
                            </x-ui.button>
                            <x-ui.button :href="$publicLinkLocation['price_url']" variant="secondary" size="sm" target="_blank" rel="noopener">
                                <x-ui.icon name="class-pass-plans" class="h-4 w-4" />
                                {{ __('app.public_price') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="sm" data-copy-button data-copy-value="{{ $publicLinkLocation['price_url'] }}" data-copy-success-label="{{ __('app.copied') }}">
                                <x-ui.icon name="copy" class="h-4 w-4" />
                                <span data-copy-label>{{ __('app.copy_link') }}</span>
                            </x-ui.button>
                            <x-ui.button :href="$publicLinkLocation['schedule_embed_url']" variant="ghost" size="sm" target="_blank" rel="noopener">
                                <x-ui.icon name="external" class="h-4 w-4" />
                                {{ __('app.public_schedule_embed') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="sm" data-copy-button data-copy-value="{{ $publicLinkLocation['schedule_embed_url'] }}" data-copy-success-label="{{ __('app.copied') }}">
                                <x-ui.icon name="copy" class="h-4 w-4" />
                                <span data-copy-label>{{ __('app.copy_link') }}</span>
                            </x-ui.button>
                            <x-ui.button :href="$publicLinkLocation['price_embed_url']" variant="ghost" size="sm" target="_blank" rel="noopener">
                                <x-ui.icon name="external" class="h-4 w-4" />
                                {{ __('app.public_price_embed') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="sm" data-copy-button data-copy-value="{{ $publicLinkLocation['price_embed_url'] }}" data-copy-success-label="{{ __('app.copied') }}">
                                <x-ui.icon name="copy" class="h-4 w-4" />
                                <span data-copy-label>{{ __('app.copy_link') }}</span>
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
    @elseif ($activeTab === 'pass_rules')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'pass_rules']) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.class-pass-cancellation-rules-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'schedule_view')
        <form method="POST" action="{{ route('dashboard.accounts.update', [$account, 'tab' => 'schedule_view']) }}" class="mt-6 max-w-4xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.schedule-view-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @elseif ($activeTab === 'customer_notifications')
        @include('accounts.customer-notification-settings')
    @elseif ($activeTab === 'api')
        @include('accounts.api-tokens', ['apiTokens' => $apiTokens])
    @elseif ($activeTab === 'ai')
        @include('accounts.ai-telegram-settings')
    @else
        <form method="POST" action="{{ route('dashboard.accounts.update', $account) }}" enctype="multipart/form-data" class="mt-6 max-w-6xl space-y-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="brand_tab" value="business">

            @include('accounts.form-fields', ['splitContactPanel' => true])

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @endif
@endsection
