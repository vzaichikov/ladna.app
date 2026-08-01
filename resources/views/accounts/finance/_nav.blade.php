@php
    $financeNavItems = collect([
        ['section' => 'payments', 'label' => __('app.payments'), 'icon' => 'payments', 'tone' => 'payments', 'route' => 'dashboard.accounts.payments.index', 'show' => auth()->user()?->can('viewStudioFinancialReports', $account)],
        ['section' => 'cash', 'label' => __('app.cash_overview'), 'icon' => 'wallet', 'tone' => 'cash', 'route' => 'dashboard.accounts.cash.index', 'show' => auth()->user()?->can('manageStudioCashflow', $account)],
        ['section' => 'expenses', 'label' => __('app.operational_expenses'), 'icon' => 'minus', 'tone' => 'expenses', 'route' => 'dashboard.accounts.expenses.index', 'show' => auth()->user()?->can('manageStudioCashflow', $account)],
        ['section' => 'payroll', 'label' => __('app.payroll_periods'), 'icon' => 'calendar-days', 'tone' => 'payroll', 'route' => 'dashboard.accounts.payroll.index', 'show' => auth()->user()?->can('manageStudioPayroll', $account)],
        ['section' => 'financial', 'label' => __('app.financial_report'), 'icon' => 'reports', 'tone' => 'reports', 'route' => 'dashboard.accounts.reports.financial', 'show' => auth()->user()?->can('viewStudioFinancialReports', $account)],
        ['section' => 'earnings', 'label' => __('app.earnings_report'), 'icon' => 'reports', 'tone' => 'reports', 'route' => 'dashboard.accounts.reports.earnings', 'show' => auth()->user()?->can('viewStudioFinancialReports', $account)],
        ['section' => 'rentals', 'label' => __('app.rental_report'), 'icon' => 'reports', 'tone' => 'reports', 'route' => 'dashboard.accounts.reports.rentals', 'show' => auth()->user()?->can('viewStudioFinancialReports', $account)],
    ])->where('show');
@endphp

<nav class="mt-5 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('app.finance_navigation') }}">
    @foreach ($financeNavItems as $item)
        @php
            $isActive = ($financeSection ?? null) === $item['section'];
            $activeClasses = match ($item['tone']) {
                'cash' => 'border-amber-400 bg-amber-100 text-amber-950 shadow-sm',
                'expenses' => 'border-rose-300 bg-rose-100 text-rose-900 shadow-sm',
                'payroll' => 'border-emerald-300 bg-emerald-100 text-emerald-900 shadow-sm',
                'reports' => 'border-violet-crm-500 bg-violet-crm-100 text-violet-crm-700 shadow-sm',
                default => 'border-sky-300 bg-sky-100 text-sky-900 shadow-sm',
            };
            $inactiveClasses = match ($item['tone']) {
                'cash' => 'border-amber-200 bg-amber-50 text-amber-900 hover:border-amber-400 hover:bg-amber-100',
                'expenses' => 'border-rose-200 bg-rose-50 text-rose-800 hover:border-rose-300 hover:bg-rose-100',
                'payroll' => 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:border-emerald-300 hover:bg-emerald-100',
                'reports' => 'border-violet-crm-100 bg-violet-crm-50 text-violet-crm-700 hover:border-violet-crm-500 hover:bg-violet-crm-100',
                default => 'border-sky-200 bg-sky-50 text-sky-800 hover:border-sky-300 hover:bg-sky-100',
            };
        @endphp
        <a href="{{ route($item['route'], $account) }}" @if ($isActive) aria-current="page" @endif @class([
            'crm-focus inline-flex min-h-11 shrink-0 items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold transition',
            $activeClasses => $isActive,
            $inactiveClasses => ! $isActive,
        ])>
            <x-ui.icon :name="$item['icon']" class="h-4 w-4" />
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>
