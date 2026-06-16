@php
    $routeName = request()->route()?->getName() ?? '';
    $candidateAccount = $account ?? null;
    $activeAccount = $candidateAccount instanceof \App\Models\Account && $candidateAccount->exists ? $candidateAccount : null;
    $showAccountNav = $activeAccount && str_starts_with($routeName, 'dashboard.accounts.');
    $sidebarAccount = $showAccountNav ? $activeAccount : null;

    $primaryNav = [
        [
            'label' => __('app.dashboard'),
            'icon' => 'dashboard',
            'href' => route('dashboard.index'),
            'active' => request()->routeIs('dashboard.index'),
        ],
        [
            'label' => __('app.accounts'),
            'icon' => 'accounts',
            'href' => route('dashboard.accounts.index'),
            'active' => request()->routeIs('dashboard.accounts.*'),
        ],
    ];

    $accountNav = $showAccountNav ? [
        [
            'label' => __('app.locations'),
            'icon' => 'locations',
            'href' => route('dashboard.accounts.locations.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.locations.*'),
        ],
        [
            'label' => __('app.rooms'),
            'icon' => 'rooms',
            'href' => route('dashboard.accounts.rooms.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.rooms.*'),
        ],
        [
            'label' => __('app.activity_directions'),
            'icon' => 'directions',
            'href' => route('dashboard.accounts.activity-directions.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.activity-directions.*'),
        ],
        [
            'label' => __('app.class_types'),
            'icon' => 'class-types',
            'href' => route('dashboard.accounts.class-types.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.class-types.*'),
        ],
        [
            'label' => __('app.instructors'),
            'icon' => 'instructors',
            'href' => route('dashboard.accounts.instructors.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.instructors.*'),
        ],
        [
            'label' => __('app.schedule_series'),
            'icon' => 'schedule',
            'href' => route('dashboard.accounts.schedule-series.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.schedule-series.*'),
        ],
        [
            'label' => __('app.generated_classes'),
            'icon' => 'generated-classes',
            'href' => route('dashboard.accounts.scheduled-classes.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.scheduled-classes.*'),
        ],
    ] : [];

    $platformNav = auth()->user()?->isPlatformAdmin() ? [[
        'label' => __('app.platform'),
        'icon' => 'platform',
        'href' => route('platform.index'),
        'active' => request()->routeIs('platform.*'),
    ]] : [];

    $userInitial = mb_substr(auth()->user()?->name ?? __('app.app_name'), 0, 1);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', __('app.app_name'))</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-canvas text-slate-950 antialiased">
        <div class="min-h-screen lg:flex">
            <div data-sidebar-backdrop class="fixed inset-0 z-30 hidden bg-slate-950/50 backdrop-blur-sm lg:hidden"></div>

            <aside
                data-sidebar
                class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col overflow-y-auto bg-ink-950 px-4 py-5 text-white shadow-2xl transition-transform duration-200 lg:translate-x-0"
            >
                <div class="flex items-center justify-between gap-3 px-1">
                    <a href="{{ route('dashboard.index') }}" class="flex items-center gap-3">
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/5 ring-1 ring-white/10">
                            <img src="{{ asset('brand/charmpole-icon.svg') }}" alt="" class="h-9 w-9">
                        </span>
                        <span class="text-lg font-semibold">
                            <span class="text-brand-500">Charm</span>
                            <span class="text-slate-100">CRM</span>
                        </span>
                    </a>
                    <button type="button" data-sidebar-close class="rounded-lg p-2 text-slate-400 transition hover:bg-white/10 hover:text-white lg:hidden">
                        <x-ui.icon name="close" class="h-5 w-5" />
                    </button>
                </div>

                <nav class="mt-8 space-y-7 text-sm font-medium">
                    <div>
                        <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.workspace') }}</div>
                        <div class="mt-3 space-y-1">
                            @foreach ($primaryNav as $item)
                                <a href="{{ $item['href'] }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                    <x-ui.icon :name="$item['icon']" class="h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400' }}" />
                                    <span>{{ $item['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    @if ($accountNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.configuration') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($accountNav as $item)
                                    <a href="{{ $item['href'] }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                        <x-ui.icon :name="$item['icon']" class="h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400' }}" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($platformNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.platform') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($platformNav as $item)
                                    <a href="{{ $item['href'] }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                        <x-ui.icon :name="$item['icon']" class="h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400' }}" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </nav>

                <div class="mt-auto space-y-3 pt-8">
                    @if ($sidebarAccount)
                        <div class="overflow-hidden rounded-xl border border-white/10 bg-white/10">
                            <div class="flex aspect-[1.65] items-center justify-center bg-[radial-gradient(circle_at_20%_20%,rgba(233,30,99,0.38),transparent_35%),linear-gradient(135deg,rgba(124,58,237,0.28),rgba(5,10,24,0.96))]">
                                <img src="{{ asset('brand/charmpole-icon.svg') }}" alt="" class="h-24 w-24 opacity-95">
                            </div>
                            <div class="p-4">
                                <div class="font-semibold text-white">{{ $sidebarAccount->name }}</div>
                                <div class="mt-1 text-sm text-slate-400">{{ $sidebarAccount->slug }}</div>
                                <div class="mt-3 flex items-center gap-2 text-xs font-semibold text-emerald-300">
                                    <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                                    {{ __('app.active') }}
                                </div>
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('locale.update') }}">
                        @csrf
                        <label class="sr-only" for="app-locale">{{ __('app.default_language') }}</label>
                        <select id="app-locale" name="locale" onchange="this.form.submit()" class="w-full rounded-lg border border-white/10 bg-white/10 px-3 py-2.5 text-sm font-semibold text-white outline-none transition focus:border-brand-500">
                            @foreach (config('charm.locales') as $locale => $label)
                                <option value="{{ $locale }}" class="text-slate-950" @selected(app()->getLocale() === $locale)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </aside>

            <div class="min-h-screen flex-1 lg:pl-72">
                <header class="sticky top-0 z-20 border-b border-slate-200/80 bg-white/90 backdrop-blur">
                    <div class="flex min-h-16 items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center gap-3">
                            <button type="button" data-sidebar-open class="rounded-lg border border-slate-200 bg-white p-2 text-slate-700 shadow-xs transition hover:bg-slate-50 lg:hidden">
                                <x-ui.icon name="menu" class="h-5 w-5" />
                            </button>
                            <div class="hidden items-center gap-2 text-sm font-semibold text-slate-500 sm:flex">
                                @if (request()->routeIs('dashboard.accounts.*') && $activeAccount)
                                    <a href="{{ route('dashboard.accounts.index') }}" class="text-brand-600 hover:text-brand-700">{{ __('app.accounts') }}</a>
                                    <x-ui.icon name="chevron-right" class="h-4 w-4 text-slate-300" />
                                    <span class="text-slate-950">{{ $activeAccount->name }}</span>
                                @else
                                    <span>{{ __('app.app_name') }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="hidden rounded-lg px-3 py-2 text-sm font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-950 sm:inline-flex">
                                    {{ __('app.logout') }}
                                </button>
                            </form>
                            <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-xs">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-violet-crm-600 text-sm font-semibold text-white">{{ $userInitial }}</span>
                                <div class="hidden text-sm sm:block">
                                    <div class="font-semibold text-slate-950">{{ auth()->user()?->name }}</div>
                                    <div class="text-xs text-slate-500">{{ auth()->user()?->isPlatformAdmin() ? __('app.platform_admin') : __('app.owner') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="px-4 py-6 sm:px-6 lg:px-8">
                    @if (session('status'))
                        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 shadow-xs">
                            {{ session('status') }}
                        </div>
                    @endif

                    @yield('content')
                </main>
            </div>
        </div>

        <div
            id="delete-confirmation-modal"
            class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="delete-confirmation-title"
        >
            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-2xl">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-700">
                        <x-ui.icon name="trash" class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 id="delete-confirmation-title" class="text-lg font-semibold text-slate-950">
                            {{ __('app.confirm_delete_title') }}
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">
                            {{ __('app.confirm_delete_body') }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <x-ui.button type="button" variant="secondary" data-confirm-cancel>
                        {{ __('app.cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="danger" data-confirm-accept>
                        {{ __('app.delete') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    </body>
</html>
