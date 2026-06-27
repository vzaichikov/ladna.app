@php
    $routeName = request()->route()?->getName() ?? '';
    $candidateAccount = $account ?? null;
    $activeAccount = $candidateAccount instanceof \App\Models\Account && $candidateAccount->exists ? $candidateAccount : null;
    $showAccountNav = $activeAccount && str_starts_with($routeName, 'dashboard.accounts.');
    $sidebarAccount = $showAccountNav ? $activeAccount : null;
    $authUser = auth()->user();
    $isPlatformAdmin = $authUser?->isPlatformAdmin() ?? false;
    $accountMembership = $showAccountNav && $authUser ? $activeAccount->membershipFor($authUser) : null;
    $trainerProfile = $accountMembership?->role === \App\Enums\AccountRole::Trainer
        ? $activeAccount->trainers()->with('trainerType')->whereBelongsTo($authUser, 'user')->first()
        : null;
    $userRoleLabel = match (true) {
        $isPlatformAdmin => __('app.platform_admin'),
        $trainerProfile?->trainerType !== null => $trainerProfile->trainerType->name,
        $accountMembership?->role !== null => __($accountMembership->role->labelKey()),
        default => __('app.owner'),
    };

    $primaryNav = $isPlatformAdmin ? [
        [
            'label' => __('app.dashboard'),
            'icon' => 'dashboard',
            'href' => route('platform.index'),
            'active' => request()->routeIs('platform.index'),
        ],
        [
            'label' => __('app.account'),
            'icon' => 'user',
            'href' => route('platform.account.edit'),
            'active' => request()->routeIs('platform.account.*'),
        ],
        [
            'label' => __('app.accounts'),
            'icon' => 'accounts',
            'href' => route('platform.accounts.index'),
            'active' => request()->routeIs('platform.accounts.*'),
        ],
        [
            'label' => __('app.payments'),
            'icon' => 'payments',
            'href' => route('platform.payments.index'),
            'active' => request()->routeIs('platform.payments.*'),
        ],
    ] : [];

    $canManageSchedule = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageSchedule);
    $canManageClients = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageClients);
    $canManageBookings = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageBookings);
    $canManageWebsiteLeads = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageWebsiteLeads);
    $canManageCustomerClassPasses = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageCustomerClassPasses);
    $canViewActivityLog = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ViewActivityLog);
    $canMarkAttendance = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::MarkAttendance);
    $canManageTrainers = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageTrainers);
    $canManageStudioSettings = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageStudioSettings);
    $canManageClassPassPlans = $showAccountNav && $activeAccount->isOwnedBy($authUser);
    $canViewTariffPayments = $showAccountNav && $authUser && $activeAccount->isOwnedBy($authUser);
    $subscriptionAccess = $showAccountNav ? app(\App\Support\SaasBilling\AccountSubscriptionAccess::class) : null;
    $subscriptionWarning = $showAccountNav && $subscriptionAccess?->shouldShowWarning($activeAccount);
    $subscriptionCanEdit = ! $showAccountNav || $subscriptionAccess?->canEditStudio($activeAccount);
    $subscriptionWarningMessage = match (true) {
        $showAccountNav && $subscriptionAccess?->requiresInitialDemoPayment($activeAccount) => __('app.demo_payment_required_readonly'),
        $subscriptionCanEdit => __('app.subscription_past_due_warning'),
        default => __('app.subscription_expired_readonly'),
    };
    $supportUrl = \App\Models\SystemSetting::stringValue(\App\Models\SystemSetting::SupportUrlKey);
    $classFormatNav = [];

    if ($showAccountNav && $canManageStudioSettings) {
        foreach (\App\Support\ScheduleKindRegistry::all() as $scheduleKindValue => $scheduleKindDefinition) {
            if (! $activeAccount->hasScheduleKindEnabled($scheduleKindValue)) {
                continue;
            }

            $routeName = 'dashboard.accounts.'.$scheduleKindDefinition['route_name'].'.index';
            $activeRoutePattern = 'dashboard.accounts.'.$scheduleKindDefinition['route_name'].'.*';
            $classFormatNav[] = [
                'label' => __('app.'.$scheduleKindDefinition['title_key']),
                'icon' => $scheduleKindDefinition['icon'],
                'href' => route($routeName, $activeAccount),
                'active' => request()->routeIs($activeRoutePattern)
                    || ($scheduleKindValue === \App\Enums\ScheduleKind::GroupClass->value && request()->routeIs('dashboard.accounts.class-types.*')),
            ];
        }
    }

    $studioNav = $showAccountNav ? [
        [
            'label' => __('app.current'),
            'icon' => 'dashboard',
            'href' => route('dashboard.accounts.show', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.show'),
        ],
        ...($canManageSchedule || $canManageBookings || $canMarkAttendance ? [[
            'label' => __('app.generated_classes'),
            'icon' => 'generated-classes',
            'href' => route('dashboard.accounts.scheduled-classes.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.scheduled-classes.*'),
        ]] : []),
        ...($canManageClients ? [[
            'label' => __('app.customers'),
            'icon' => 'accounts',
            'href' => route('dashboard.accounts.customers.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.customers.*'),
        ]] : []),
        ...($canManageWebsiteLeads ? [[
            'label' => __('app.website_leads'),
            'icon' => 'website-leads',
            'href' => route('dashboard.accounts.website-leads.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.website-leads.*'),
        ]] : []),
        ...($canManageCustomerClassPasses ? [[
            'label' => __('app.customer_class_passes'),
            'icon' => 'class-pass-plans',
            'href' => route('dashboard.accounts.customer-class-passes.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.customer-class-passes.*'),
        ]] : []),
        ...($canViewTariffPayments ? [[
            'label' => __('app.payments'),
            'icon' => 'payments',
            'href' => route('dashboard.accounts.payments.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.payments.*'),
        ]] : []),
    ] : [];

    $studioSettingsNav = $showAccountNav ? [
        ...($canManageStudioSettings ? [
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
            ...$classFormatNav,
        ] : []),
        ...($canManageSchedule && $activeAccount->hasScheduleKindEnabled(\App\Enums\ScheduleKind::GroupClass) ? [[
            'label' => __('app.schedule_series'),
            'icon' => 'schedule',
            'href' => route('dashboard.accounts.schedule-series.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.schedule-series.*'),
        ]] : []),
        ...($canManageClassPassPlans ? [[
            'label' => __('app.class_pass_plans'),
            'icon' => 'class-pass-plans',
            'href' => route('dashboard.accounts.class-pass-plans.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.class-pass-plans.*'),
        ], [
            'label' => __('app.class_pass_segments'),
            'icon' => 'class-pass-plans',
            'href' => route('dashboard.accounts.class-pass-segments.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.class-pass-segments.*'),
        ]] : []),
        ...($canManageTrainers ? [[
            'label' => __('app.trainers'),
            'icon' => 'trainers',
            'href' => route('dashboard.accounts.trainers.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.trainers.*'),
        ]] : []),
        ...($canManageStudioSettings ? [
            [
                'label' => __('app.trainer_types'),
                'icon' => 'trainer-levels',
                'href' => route('dashboard.accounts.trainer-types.index', $activeAccount),
                'active' => request()->routeIs('dashboard.accounts.trainer-types.*'),
            ],
        ] : []),
        ...($canManageClassPassPlans ? [
            [
                'label' => __('app.integrations'),
                'icon' => 'integrations',
                'href' => route('dashboard.accounts.integrations.index', $activeAccount),
                'active' => request()->routeIs('dashboard.accounts.integrations.*'),
            ],
        ] : []),
        ...($canManageStudioSettings ? [[
            'label' => __('app.my_brand'),
            'icon' => 'sparkles',
            'href' => route('dashboard.accounts.general-settings.edit', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.general-settings.*', 'dashboard.accounts.brand.*'),
        ]] : []),
    ] : [];

    $accountSettingsNav = $showAccountNav ? [
        ...($canManageStudioSettings ? [[
            'label' => __('app.my_account'),
            'icon' => 'user',
            'href' => route('dashboard.accounts.owner-profile.edit', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.owner-profile.*'),
        ]] : []),
        ...($canViewTariffPayments ? [[
            'label' => __('app.tariff_payments'),
            'icon' => 'payments',
            'href' => route('dashboard.accounts.tariff-payments.show', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.tariff-payments.*'),
        ]] : []),
        ...($canViewActivityLog ? [[
            'label' => __('app.account_activity_log'),
            'icon' => 'activity-log',
            'href' => route('dashboard.accounts.activity-logs.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.activity-logs.*'),
        ]] : []),
    ] : [];

    $platformSettingsNav = $isPlatformAdmin ? [
        [
            'label' => __('app.system_settings'),
            'icon' => 'settings',
            'href' => route('platform.settings.edit'),
            'active' => request()->routeIs('platform.settings.*'),
        ],
        [
            'label' => __('app.integrations'),
            'icon' => 'integrations',
            'href' => route('platform.integrations.index'),
            'active' => request()->routeIs('platform.integrations.*'),
        ],
        [
            'label' => __('app.scheduled_tasks'),
            'icon' => 'scheduled-tasks',
            'href' => route('platform.scheduled-tasks.index'),
            'active' => request()->routeIs('platform.scheduled-tasks.*'),
        ],
    ] : [];

    $userInitial = mb_substr($authUser?->name ?? __('app.app_name'), 0, 1);
    $systemAppearance = $systemAppearance ?? \App\Support\SystemAppearance::current();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', __('app.app_name'))</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $systemAppearance['google_fonts_url'] }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('head')
    </head>
    <body
        class="min-h-screen bg-canvas text-slate-950 antialiased"
        data-phone-mask-error="{{ __('app.phone_mask_error') }}"
        data-phone-mask-no-results="{{ __('app.phone_mask_no_results') }}"
        data-phone-mask-search="{{ __('app.phone_mask_search') }}"
        data-phone-mask-success="{{ __('app.phone_mask_success') }}"
        style="--app-font-family: '{{ $systemAppearance['css_family'] }}';"
    >
        <div class="min-h-screen lg:flex">
            <div data-sidebar-backdrop class="fixed inset-0 z-30 hidden bg-slate-950/50 backdrop-blur-sm lg:hidden"></div>

            <aside
                data-sidebar
                class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col overflow-y-auto bg-[#3B223F] bg-[linear-gradient(180deg,#3B223F_0%,#2B1731_58%,#3B223F_100%)] px-4 py-5 text-white shadow-2xl transition-transform duration-200 lg:translate-x-0"
            >
                <div class="flex items-center justify-between gap-3 px-1">
                    <a href="{{ $isPlatformAdmin ? route('platform.index') : route('dashboard.index') }}" class="rounded-xl px-1 py-1 transition hover:bg-white/5">
                        <x-ui.app-logo
                            text-class="text-white"
                            tagline-class="text-violet-crm-100/80"
                            mark-wrapper-class="flex h-12 w-12 items-center justify-center rounded-[14px] bg-[#FAF8F5] p-2 shadow-[0_10px_24px_rgba(20,10,24,0.22)] ring-1 ring-white/60"
                        />
                    </a>
                    <button type="button" data-sidebar-close class="rounded-lg p-2 text-slate-400 transition hover:bg-white/10 hover:text-white lg:hidden">
                        <x-ui.icon name="close" class="h-5 w-5" />
                    </button>
                </div>

                <nav class="mt-8 space-y-7 text-sm font-medium">
                    @if ($primaryNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.platform') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($primaryNav as $item)
                                    <a href="{{ $item['href'] }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                        <x-ui.icon :name="$item['icon']" class="h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400' }}" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($studioNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.my_studio') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($studioNav as $item)
                                    <a href="{{ $item['href'] }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                        <x-ui.icon :name="$item['icon']" class="h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400' }}" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($studioSettingsNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.studio_settings') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($studioSettingsNav as $item)
                                    <a href="{{ $item['href'] }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                        <x-ui.icon :name="$item['icon']" class="h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400' }}" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($accountSettingsNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.account_settings') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($accountSettingsNav as $item)
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
                    @if ($platformSettingsNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.configuration') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($platformSettingsNav as $item)
                                    <a href="{{ $item['href'] }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                        <x-ui.icon :name="$item['icon']" class="h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400' }}" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($sidebarAccount)
                        <div class="crm-studio-card">
                            <div class="crm-studio-logo-panel flex aspect-[1.65] items-center justify-center">
                                <img src="{{ $sidebarAccount->logoUrl() }}" alt="" class="max-h-24 max-w-28 object-contain opacity-95">
                            </div>
                            <div class="p-4">
                                <div class="font-semibold text-white">{{ $sidebarAccount->name }}</div>
                                <div class="mt-1 text-sm text-violet-crm-100/80">{{ $sidebarAccount->slug }}</div>
                                <div class="mt-3">
                                    <span class="{{ $subscriptionCanEdit ? 'crm-status-active' : 'crm-status-danger' }}">
                                        {{ $subscriptionCanEdit ? __('app.active') : __('app.expired') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('locale.update') }}">
                        @csrf
                        <label class="sr-only" for="app-locale">{{ __('app.default_language') }}</label>
                        <select id="app-locale" name="locale" onchange="this.form.submit()" class="w-full rounded-lg border border-white/10 bg-white/10 px-3 py-2.5 text-sm font-semibold text-white outline-none transition focus:border-brand-500">
                            @foreach (config('ladna.locales') as $locale => $label)
                                <option value="{{ $locale }}" class="text-slate-950" @selected(app()->getLocale() === $locale)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </aside>

            <div class="min-h-screen flex-1 lg:pl-72">
                <header class="sticky top-0 z-20 border-b border-stone-200/80 bg-white/90 backdrop-blur">
                    <div class="flex min-h-16 items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center gap-3">
                            <button type="button" data-sidebar-open class="rounded-lg border border-stone-200 bg-white p-2 text-slate-700 shadow-xs transition hover:bg-brand-50 lg:hidden">
                                <x-ui.icon name="menu" class="h-5 w-5" />
                            </button>
                            <div class="hidden items-center gap-2 text-sm font-semibold text-slate-500 sm:flex">
                                @if (request()->routeIs('dashboard.accounts.*') && $activeAccount)
                                    <span>{{ __('app.workspace') }}</span>
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
                                <button type="submit" class="hidden rounded-lg px-3 py-2 text-sm font-semibold text-slate-500 transition hover:bg-brand-50 hover:text-slate-950 sm:inline-flex">
                                    {{ __('app.logout') }}
                                </button>
                            </form>
                            <div class="flex items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2 shadow-xs">
                                @if ($authUser?->avatarUrl())
                                    <img src="{{ $authUser->avatarUrl() }}" alt="" class="h-8 w-8 rounded-full object-cover">
                                @else
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ $userInitial }}</span>
                                @endif
                                <div class="hidden text-sm sm:block">
                                    <div class="font-semibold text-slate-950">{{ $authUser?->name }}</div>
                                    <div class="text-xs text-slate-500">{{ $userRoleLabel }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </header>

                @if ($subscriptionWarning)
                    <div class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950 sm:px-6 lg:px-8">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span>{{ $subscriptionWarningMessage }}</span>
                            <span class="flex flex-wrap gap-3">
                                @if ($canViewTariffPayments)
                                    <a href="{{ route('dashboard.accounts.tariff-payments.show', $activeAccount) }}" class="text-[#3B223F] underline decoration-[#A78AB9] underline-offset-4">
                                        {{ __('app.pay_now') }}
                                    </a>
                                @endif
                                @if ($supportUrl)
                                    <a href="{{ $supportUrl }}" class="text-[#3B223F] underline decoration-[#A78AB9] underline-offset-4">
                                        {{ __('app.support') }}
                                    </a>
                                @endif
                            </span>
                        </div>
                    </div>
                @endif

                <main class="px-4 py-6 sm:px-6 lg:px-8">
                    @if (session('status'))
                        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 shadow-xs">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if ($errors->has('subscription'))
                        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950 shadow-xs">
                            {{ $errors->first('subscription') }}
                        </div>
                    @endif

                    <div
                        data-async-status
                        data-error-message="{{ __('app.async_request_failed') }}"
                        data-validation-message="{{ __('app.async_validation_failed') }}"
                        class="mb-6 hidden rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 shadow-xs"
                    ></div>

                    @yield('content')
                </main>

                <x-ui.app-footer :version="$applicationVersion" />
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
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-700" data-confirm-icon data-default-icon="trash-2">
                        <x-ui.icon name="trash" class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 id="delete-confirmation-title" class="text-lg font-semibold text-slate-950" data-confirm-title data-default-text="{{ __('app.confirm_delete_title') }}">
                            {{ __('app.confirm_delete_title') }}
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500" data-confirm-body data-default-text="{{ __('app.confirm_delete_body') }}">
                            {{ __('app.confirm_delete_body') }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <x-ui.button type="button" variant="secondary" data-confirm-cancel>
                        {{ __('app.cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="danger" data-confirm-accept data-default-text="{{ __('app.delete') }}">
                        {{ __('app.delete') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    </body>
</html>
