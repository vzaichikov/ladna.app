@php
    $routeName = request()->route()?->getName() ?? '';
    $candidateAccount = $account ?? null;
    $activeAccount = $candidateAccount instanceof \App\Models\Account && $candidateAccount->exists ? $candidateAccount : null;
    $showAccountNav = $activeAccount && str_starts_with($routeName, 'dashboard.accounts.');
    $sidebarAccount = $showAccountNav ? $activeAccount : null;
    $authUser = auth()->user();
    $isPlatformAdmin = $authUser?->isPlatformAdmin() ?? false;
    $isReadOnlyDemo = $activeAccount?->isReadOnlyDemo() ?? false;

    if (! $activeAccount && $authUser && ! $isPlatformAdmin) {
        $userAccounts = $authUser->accounts()->limit(2)->get();
        $isReadOnlyDemo = $userAccounts->count() === 1 && $userAccounts->first()?->isReadOnlyDemo();
    }

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
        [
            'label' => __('app.sms_payments'),
            'icon' => 'payments',
            'href' => route('platform.sms-payments.index'),
            'active' => request()->routeIs('platform.sms-payments.*'),
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
    $canManageStudioCashflow = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageStudioCashflow);
    $canViewStudioFinancialReports = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ViewStudioFinancialReports);
    $canManageStudioPayroll = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageStudioPayroll);
    $canInteractWithTelegramBot = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::InteractWithTelegramBot);
    $canManageEvents = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::ManageEvents);
    $canCheckInEventTickets = $showAccountNav && $authUser && $activeAccount->userCan($authUser, \App\Enums\StudioPermission::CheckInEventTickets);
    $canViewReports = $showAccountNav && $authUser && $authUser->can('viewReports', $activeAccount);
    $showAssistantWidget = $canInteractWithTelegramBot && \App\Models\PlatformAiSetting::ownerAssistantEnabled();
    $assistantImageInferenceEnabled = $showAssistantWidget && \App\Models\PlatformAiSetting::imageInferenceEnabled();
    $assistantVoiceInputEnabled = $showAssistantWidget && \App\Models\PlatformAiSetting::ownerVoiceInputEnabled();
    $canManageClassPassPlans = $showAccountNav && $activeAccount->isOwnedBy($authUser);
    $canViewPayments = $canViewStudioFinancialReports;
    $canViewTariffPayments = ! $isReadOnlyDemo && $showAccountNav && $authUser && $activeAccount->isOwnedBy($authUser);
    $subscriptionAccess = $showAccountNav ? app(\App\Support\SaasBilling\AccountSubscriptionAccess::class) : null;
    $subscriptionWarning = ! $isReadOnlyDemo && $showAccountNav && $subscriptionAccess?->shouldShowWarning($activeAccount);
    $subscriptionCanEdit = ! $showAccountNav || $subscriptionAccess?->canEditStudio($activeAccount);
    $subscriptionWarningMessage = match (true) {
        $showAccountNav && $subscriptionAccess?->requiresInitialDemoPayment($activeAccount) => __('app.demo_payment_required_readonly'),
        $subscriptionCanEdit => __('app.subscription_past_due_warning'),
        default => __('app.subscription_expired_readonly'),
    };
    $supportUrl = \App\Models\SystemSetting::stringValue(\App\Models\SystemSetting::SupportUrlKey);
    $ownerTelegramBotUsername = $showAccountNav
        ? (string) \App\Models\TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('profile', \App\Enums\TelegramBotProfile::Owner->value)
            ->where('is_enabled', true)
            ->whereNotNull('bot_username')
            ->latest('id')
            ->value('bot_username')
        : '';
    $ownerTelegramBotUsername = trim($ownerTelegramBotUsername);
    $ownerTelegramBotUrl = null;

    if ($ownerTelegramBotUsername !== '') {
        $ownerTelegramBotUrl = str_starts_with(strtolower($ownerTelegramBotUsername), 'http://')
            || str_starts_with(strtolower($ownerTelegramBotUsername), 'https://')
            || str_starts_with(strtolower($ownerTelegramBotUsername), 'tg://')
            ? $ownerTelegramBotUsername
            : 'https://t.me/'.ltrim($ownerTelegramBotUsername, '@');
    }

    $customerTelegramBotConfigured = $showAccountNav && $canManageStudioSettings
        ? $activeAccount->telegramBotInstallations()
            ->where('scope_type', 'account')
            ->where('scope_id', $activeAccount->id)
            ->where('profile', \App\Enums\TelegramBotProfile::Customer->value)
            ->whereNotNull('token_last_four')
            ->exists()
        : false;

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
        ...($trainerProfile && $activeAccount->trainerPrivateTimeframesEnabled() ? [[
            'label' => __('app.trainer_private_timeframes'),
            'icon' => 'schedule',
            'href' => route('dashboard.accounts.trainer-private-timeframes.mine', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.trainer-private-timeframes.*'),
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
        ...($canManageEvents || $canCheckInEventTickets ? [[
            'label' => __('app.events'),
            'icon' => 'calendar-days',
            'href' => route('dashboard.accounts.events.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.events.*'),
        ]] : []),
        ...($canViewReports || $canViewStudioFinancialReports ? [[
            'label' => __('app.reports'),
            'icon' => 'reports',
            'href' => route('dashboard.accounts.reports.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.reports.*'),
        ]] : []),
        ...($canViewReports && $activeAccount->allowsRtspCameras() ? [[
            'label' => __('app.cameras'),
            'icon' => 'video',
            'href' => route('dashboard.accounts.cameras.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.cameras.*'),
        ]] : []),
    ] : [];

    $financeNav = $showAccountNav ? [
        ...($canViewPayments ? [[
            'label' => __('app.payments'),
            'icon' => 'payments',
            'href' => route('dashboard.accounts.payments.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.payments.*'),
        ]] : []),
        ...($canManageStudioCashflow ? [[
            'label' => __('app.cash_overview'),
            'icon' => 'wallet',
            'href' => route('dashboard.accounts.cash.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.cash.*', 'dashboard.accounts.cashbox-reconciliations.*', 'dashboard.accounts.finance-epochs.*'),
        ], [
            'label' => __('app.operational_expenses'),
            'icon' => 'minus',
            'href' => route('dashboard.accounts.expenses.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.expenses.*', 'dashboard.accounts.expense-categories.*'),
        ]] : []),
        ...($canManageStudioPayroll ? [[
            'label' => __('app.payroll_periods'),
            'icon' => 'payments',
            'href' => route('dashboard.accounts.payroll.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.payroll.*'),
        ]] : []),
    ] : [];

    $sidebarLinksNav = $showAccountNav ? [
        ...($canManageStudioSettings ? [[
            'label' => __('app.qr_links_title'),
            'description' => __('app.qr_links_sidebar_help'),
            'icon' => 'qr-code',
            'href' => route('dashboard.accounts.qr-links.show', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.qr-links.*'),
            'external' => false,
        ]] : []),
        [
            'label' => __('app.studio_landing_link'),
            'description' => __('app.studio_landing_link_help'),
            'icon' => 'globe',
            'href' => route('public.studio', $activeAccount->slug),
            'active' => false,
            'external' => true,
        ],
        ...($ownerTelegramBotUrl ? [[
            'label' => __('app.telegram_support_bot_link'),
            'description' => __('app.telegram_support_bot_link_help'),
            'icon' => 'telegram',
            'href' => $ownerTelegramBotUrl,
            'active' => false,
            'external' => true,
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
        ...($canManageStudioPayroll ? [[
            'label' => __('app.salary_settings'),
            'icon' => 'payments',
            'href' => route('dashboard.accounts.salary-models.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.salary-models.*'),
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
        ...($canManageStudioSettings ? [
            [
                'label' => __('app.my_brand'),
                'icon' => 'sparkles',
                'href' => route('dashboard.accounts.general-settings.edit', $activeAccount),
                'active' => request()->routeIs('dashboard.accounts.general-settings.*', 'dashboard.accounts.brand.*'),
            ],
            [
                'label' => __('app.notification_settings'),
                'icon' => 'bell',
                'href' => route('dashboard.accounts.notification-settings.edit', $activeAccount),
                'active' => request()->routeIs(
                    'dashboard.accounts.notification-settings.*',
                    'dashboard.accounts.customer-notification-settings.*',
                    'dashboard.accounts.trainer-notification-settings.*',
                    'dashboard.accounts.customer-telegram-bot.*',
                ),
            ],
        ] : []),
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
        ], [
            'label' => __('app.sms_account'),
            'icon' => 'bell',
            'href' => route('dashboard.accounts.sms-account.show', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.sms-account.*'),
        ]] : []),
    ] : [];

    $studioLogsNav = $showAccountNav ? [
        ...($canViewActivityLog ? [[
            'label' => __('app.account_activity_log'),
            'icon' => 'activity-log',
            'href' => route('dashboard.accounts.activity-logs.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.activity-logs.*'),
        ], [
            'label' => __('app.sms_delivery_log'),
            'icon' => 'bell',
            'href' => route('dashboard.accounts.customer-notification-logs.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.customer-notification-logs.*'),
        ], [
            'label' => __('app.trainer_telegram_alert_log'),
            'icon' => 'telegram',
            'href' => route('dashboard.accounts.trainer-telegram-alert-logs.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.trainer-telegram-alert-logs.*'),
        ]] : []),
        ...($customerTelegramBotConfigured ? [[
            'label' => __('app.customer_telegram_bot_menu'),
            'icon' => 'telegram',
            'href' => route('dashboard.accounts.telegram-connections.index', $activeAccount),
            'active' => request()->routeIs('dashboard.accounts.telegram-connections.*'),
        ]] : []),
    ] : [];

    $platformSettingsNav = $isPlatformAdmin ? [
        [
            'label' => __('app.system_settings'),
            'icon' => 'settings',
            'href' => route('platform.settings.edit'),
            'active' => request()->routeIs('platform.settings.*', 'platform.ai-usage.*'),
        ],
        [
            'label' => __('app.integrations'),
            'icon' => 'integrations',
            'href' => route('platform.integrations.index'),
            'active' => request()->routeIs('platform.integrations.*'),
        ],
        [
            'label' => __('app.email_scenarios'),
            'icon' => 'mail',
            'href' => route('platform.email-scenarios.index'),
            'active' => request()->routeIs('platform.email-scenarios.*'),
        ],
    ] : [];

    $platformLogsNav = $isPlatformAdmin ? [
        [
            'label' => __('app.telegram_support'),
            'icon' => 'telegram',
            'href' => route('platform.telegram-support.index'),
            'active' => request()->routeIs('platform.telegram-support.*'),
        ],
        [
            'label' => __('app.customer_notifications_queue_short'),
            'icon' => 'bell',
            'href' => route('platform.customer-notifications.index'),
            'active' => request()->routeIs('platform.customer-notifications.*'),
        ],
        [
            'label' => __('app.sms_delivery_log'),
            'icon' => 'activity-log',
            'href' => route('platform.sms-deliveries.index'),
            'active' => request()->routeIs('platform.sms-deliveries.*'),
        ],
        [
            'label' => __('app.email_deliveries'),
            'icon' => 'mail-check',
            'href' => route('platform.email-deliveries.index'),
            'active' => request()->routeIs('platform.email-deliveries.*'),
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
        <link rel="manifest" href="{{ route('pwa.manifest') }}">
        <link rel="apple-touch-icon" href="{{ asset('pwa/apple-touch-icon.png') }}">
        <meta name="theme-color" content="#3B223F">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ __('app.app_name') }}">
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
                    @if ($sidebarAccount)
                        <a href="{{ route('dashboard.accounts.show', $sidebarAccount) }}" class="flex min-w-0 items-center gap-3 rounded-xl px-1 py-1 transition hover:bg-white/5">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[14px] bg-[#FAF8F5] p-2 shadow-[0_10px_24px_rgba(20,10,24,0.22)] ring-1 ring-white/60">
                                <img src="{{ $sidebarAccount->logoUrl() }}" alt="" class="max-h-full max-w-full object-contain">
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold leading-5 text-white">{{ $sidebarAccount->name }}</span>
                                <span class="mt-0.5 block truncate text-xs font-medium leading-4 text-violet-crm-100/80">{{ __('app.works_on_ladna') }}</span>
                            </span>
                        </a>
                    @else
                        <a href="{{ $isPlatformAdmin ? route('platform.index') : route('dashboard.index') }}" class="rounded-xl px-1 py-1 transition hover:bg-white/5">
                            <x-ui.app-logo
                                text-class="text-white"
                                tagline-class="text-violet-crm-100/80"
                                mark-wrapper-class="flex h-12 w-12 items-center justify-center rounded-[14px] bg-[#FAF8F5] p-2 shadow-[0_10px_24px_rgba(20,10,24,0.22)] ring-1 ring-white/60"
                            />
                        </a>
                    @endif
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

                    @if ($financeNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.finance') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($financeNav as $item)
                                    <a href="{{ $item['href'] }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                        <x-ui.icon :name="$item['icon']" class="h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400' }}" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($sidebarLinksNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.links') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($sidebarLinksNav as $item)
                                    <a
                                        href="{{ $item['href'] }}"
                                        @if ($item['external']) target="_blank" rel="noopener" @endif
                                        class="group flex items-start gap-3 rounded-lg px-3 py-2.5 transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}"
                                    >
                                        <x-ui.icon :name="$item['icon']" class="mt-0.5 h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400 group-hover:text-brand-500' }}" />
                                        <span class="min-w-0 flex-1">
                                            <span class="flex min-w-0 items-center gap-1">
                                                <span class="truncate">{{ $item['label'] }}</span>
                                                @if ($item['external'])
                                                    <x-ui.icon name="external" class="h-3.5 w-3.5 shrink-0 text-slate-500 group-hover:text-violet-crm-100" />
                                                @endif
                                            </span>
                                            <span class="mt-0.5 block truncate text-[0.68rem] font-medium leading-4 text-violet-crm-100/65">{{ $item['description'] }}</span>
                                        </span>
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

                    @if ($studioLogsNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.logs') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($studioLogsNav as $item)
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

                    @if ($platformLogsNav)
                        <div>
                            <div class="px-3 text-xs font-semibold uppercase text-slate-500">{{ __('app.system_logs') }}</div>
                            <div class="mt-3 space-y-1">
                                @foreach ($platformLogsNav as $item)
                                    <a href="{{ $item['href'] }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ $item['active'] ? 'bg-white/15 text-white ring-1 ring-white/10' : 'text-slate-300 hover:bg-white/10 hover:text-white' }}">
                                        <x-ui.icon :name="$item['icon']" class="h-5 w-5 {{ $item['active'] ? 'text-brand-500' : 'text-slate-400' }}" />
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($sidebarAccount)
                        <a href="{{ route('dashboard.index') }}" class="block rounded-xl border border-white/10 bg-white/10 p-3 transition hover:bg-white/15">
                            <x-ui.app-logo
                                text-class="text-white"
                                tagline-class="text-violet-crm-100/80"
                                mark-wrapper-class="flex h-12 w-12 items-center justify-center rounded-[14px] bg-[#FAF8F5] p-2 shadow-[0_10px_24px_rgba(20,10,24,0.22)] ring-1 ring-white/60"
                            />
                        </a>
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

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" data-sidebar-logout class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-300 transition hover:bg-white/10 hover:text-white">
                            <x-ui.icon name="logout" class="h-5 w-5 text-slate-400" />
                            <span>{{ __('app.logout') }}</span>
                        </button>
                    </form>
                </div>
            </aside>

            <div class="min-h-screen min-w-0 flex-1 lg:pl-72">
                <x-ui.pwa-install-button />

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

                @if ($isReadOnlyDemo || $errors->has('demo'))
                    <x-ui.demo-readonly-banner />
                @endif

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

                    @if (session('warning'))
                        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950 shadow-xs">
                            {{ session('warning') }}
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

        <x-ui.update-reload-toast :revision="$applicationRevision" desktop-offset />

        @stack('modals')

        @if ($showAssistantWidget)
            <div
                data-assistant-chat
                data-show-url="{{ route('dashboard.accounts.assistant.show', $activeAccount) }}"
                data-send-url="{{ route('dashboard.accounts.assistant.messages.store', $activeAccount) }}"
                data-clear-url="{{ route('dashboard.accounts.assistant.destroy', $activeAccount) }}"
                data-confirm-url-template="{{ route('dashboard.accounts.assistant.actions.confirm', [$activeAccount, '__ACTION__']) }}"
                data-cancel-url-template="{{ route('dashboard.accounts.assistant.actions.cancel', [$activeAccount, '__ACTION__']) }}"
                data-csrf-token="{{ csrf_token() }}"
                data-error-message="{{ __('app.assistant_chat_error') }}"
                data-empty-message="{{ __('app.assistant_chat_empty') }}"
                data-thinking-message="{{ __('app.assistant_thinking') }}"
                data-confirm-label="{{ __('app.confirm') }}"
                data-cancel-label="{{ __('app.cancel') }}"
                data-image-label="{{ __('app.assistant_image') }}"
                data-image-add-label="{{ __('app.assistant_image_add') }}"
                data-image-remove-label="{{ __('app.assistant_image_remove') }}"
                data-image-invalid-type-message="{{ __('app.assistant_image_invalid_type') }}"
                data-image-too-large-message="{{ __('app.assistant_image_too_large') }}"
                data-image-input-enabled="{{ $assistantImageInferenceEnabled ? 'true' : 'false' }}"
                data-voice-input-enabled="{{ $assistantVoiceInputEnabled ? 'true' : 'false' }}"
                data-voice-message-label="{{ __('app.assistant_voice_message') }}"
                data-voice-permission-message="{{ __('app.assistant_voice_permission_denied') }}"
                data-voice-recording-error-message="{{ __('app.assistant_voice_recording_failed') }}"
                data-voice-too-large-message="{{ __('app.assistant_voice_too_large') }}"
                class="fixed bottom-5 right-5 z-40"
            >
                <button
                    type="button"
                    data-assistant-toggle
                    class="flex h-14 w-14 items-center justify-center rounded-full border border-[#E7DDC9] bg-white shadow-2xl shadow-slate-950/15 ring-1 ring-[#DCCFF0] transition hover:scale-[1.03] hover:bg-[#FAF8F5]"
                    aria-label="{{ __('app.owner_dashboard_chat_title') }}"
                >
                    <span class="relative h-12 w-12 overflow-hidden rounded-full bg-[#FAF8F5] ring-1 ring-[#E7DDC9]">
                        <img src="{{ asset('assets/brand/mascot/ladna-ai-chat-avatar.png') }}" alt="" class="h-full w-full object-cover">
                    </span>
                </button>

                <section
                    data-assistant-panel
                    class="absolute bottom-16 right-0 hidden w-[min(92vw,390px)] overflow-hidden rounded-xl border border-stone-200 bg-white shadow-2xl shadow-slate-950/20"
                    aria-label="{{ __('app.owner_dashboard_chat_title') }}"
                >
                    <div class="relative flex items-center justify-between border-b border-stone-100 px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="relative h-9 w-9 shrink-0 overflow-hidden rounded-full bg-[#FAF8F5] ring-1 ring-stone-200">
                                <img src="{{ asset('assets/brand/mascot/ladna-ai-chat-avatar.png') }}" alt="" class="h-full w-full object-cover">
                            </span>
                            <div>
                                <div class="text-sm font-semibold text-slate-950">{{ __('app.owner_dashboard_chat_title') }}</div>
                                <div class="text-xs text-slate-500">{{ $activeAccount->name }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" data-assistant-clear class="rounded-lg p-2 text-slate-400 transition hover:bg-rose-50 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-50" aria-label="{{ __('app.assistant_clear_chat') }}">
                                <x-ui.icon name="trash" class="h-4 w-4" />
                            </button>
                            <button type="button" data-assistant-close class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-50 hover:text-slate-700" aria-label="{{ __('app.close') }}">
                                <x-ui.icon name="close" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div data-assistant-clear-modal class="absolute inset-0 z-10 hidden items-center justify-center bg-slate-950/45 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="assistant-clear-chat-title">
                        <div class="w-full max-w-xs rounded-xl border border-slate-200 bg-white p-5 shadow-2xl">
                            <div class="flex items-start gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-700">
                                    <x-ui.icon name="trash" class="h-5 w-5" />
                                </div>
                                <div>
                                    <h2 id="assistant-clear-chat-title" class="text-base font-semibold text-slate-950">{{ __('app.assistant_clear_chat_title') }}</h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.assistant_clear_chat_body') }}</p>
                                </div>
                            </div>

                            <div class="mt-5 flex justify-end gap-2">
                                <button type="button" data-assistant-clear-cancel class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                                    {{ __('app.cancel') }}
                                </button>
                                <button type="button" data-assistant-clear-confirm class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100 disabled:opacity-60">
                                    {{ __('app.assistant_clear_chat_confirm') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div data-assistant-messages class="flex max-h-[48vh] min-h-64 flex-col gap-3 overflow-y-auto bg-slate-50 px-4 py-4"></div>

                    <div data-assistant-actions class="hidden border-t border-stone-100 bg-white px-4 py-3"></div>
                    <div data-assistant-follow-ups class="hidden border-t border-stone-100 bg-white px-4 py-3"></div>

                    <form data-assistant-form class="border-t border-stone-100 bg-white p-3">
                        <div data-assistant-drop-zone class="rounded-lg transition">
                            @if ($assistantImageInferenceEnabled)
                                <div data-assistant-image-preview class="hidden pb-2">
                                    <div class="relative inline-flex max-w-full overflow-hidden rounded-lg border border-stone-200 bg-slate-50 p-1 shadow-xs">
                                        <img data-assistant-image-preview-source alt="{{ __('app.assistant_image') }}" class="h-20 max-w-40 rounded-md object-cover">
                                        <button
                                            type="button"
                                            data-assistant-image-remove
                                            class="absolute right-1.5 top-1.5 inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-950/70 text-white shadow-sm transition hover:bg-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                                            aria-label="{{ __('app.assistant_image_remove') }}"
                                        >
                                            <x-ui.icon name="close" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            @endif

                            <div data-assistant-composer-controls class="flex gap-2">
                                @if ($assistantImageInferenceEnabled)
                                    <input
                                        data-assistant-image-input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="hidden"
                                    >
                                    <button
                                        type="button"
                                        data-assistant-image-picker
                                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-stone-200 bg-white text-slate-500 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        aria-label="{{ __('app.assistant_image_add') }}"
                                        title="{{ __('app.assistant_image_add') }}"
                                    >
                                        <x-ui.icon name="image" class="h-4 w-4" />
                                    </button>
                                @endif
                                @if ($assistantVoiceInputEnabled)
                                    <button
                                        type="button"
                                        data-assistant-voice-record
                                        class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-stone-200 bg-white text-slate-500 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        aria-label="{{ __('app.assistant_voice_record') }}"
                                        title="{{ __('app.assistant_voice_record') }}"
                                    >
                                        <x-ui.icon name="mic" class="h-4 w-4" />
                                    </button>
                                @endif
                                <input
                                    data-assistant-input
                                    class="min-w-0 flex-1 rounded-lg border border-stone-200 px-3 py-2 text-sm outline-none transition focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
                                    maxlength="2000"
                                    autocomplete="off"
                                    placeholder="{{ __('app.assistant_chat_placeholder') }}"
                                >
                                <button type="submit" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60" aria-label="{{ __('app.send') }}">
                                    <x-ui.icon name="send" class="h-4 w-4" />
                                </button>
                            </div>
                            @if ($assistantVoiceInputEnabled)
                                <div data-assistant-voice-recording class="hidden flex-col gap-2 rounded-lg border border-rose-100 bg-rose-50 p-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-rose-600"></span>
                                        <span class="min-w-0 flex-1 text-sm font-semibold text-rose-900">{{ __('app.assistant_voice_recording') }}</span>
                                        <span data-assistant-voice-timer class="shrink-0 font-mono text-sm font-semibold tabular-nums text-rose-800">00:00</span>
                                    </div>
                                    <div class="grid grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-2">
                                        <button
                                            type="button"
                                            data-assistant-voice-stop
                                            class="inline-flex h-9 w-full items-center justify-center gap-1.5 rounded-lg bg-rose-600 px-3 text-xs font-semibold text-white transition hover:bg-rose-700"
                                        >
                                            <x-ui.icon name="square" class="h-3.5 w-3.5" />
                                            {{ __('app.assistant_voice_stop') }}
                                        </button>
                                        <button
                                            type="button"
                                            data-assistant-voice-cancel
                                            class="inline-flex h-9 w-full items-center justify-center rounded-lg border border-rose-200 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:border-rose-300 hover:text-slate-950"
                                        >
                                            {{ __('app.cancel') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                            @if ($assistantImageInferenceEnabled)
                                <p class="pt-1.5 text-center text-[11px] leading-4 text-slate-400">
                                    {{ __('app.assistant_image_hint') }}
                                </p>
                            @endif
                        </div>
                    </form>
                </section>
            </div>
        @endif

        <x-ui.action-confirmation-modal />

        <div
            data-async-success-modal
            class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="async-success-title"
        >
            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-2xl">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
                        <x-ui.icon name="check" class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 id="async-success-title" class="text-lg font-semibold text-slate-950" data-async-success-title>
                            {{ __('app.success') }}
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500" data-async-success-body></p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <x-ui.button type="button" data-async-success-close>
                        {{ __('app.ok') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    </body>
</html>
