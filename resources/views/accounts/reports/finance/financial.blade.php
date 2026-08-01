@extends('layouts.app')

@section('title', __('app.financial_report_title').' - '.$account->name)

@section('content')
    <div>
        <h1 class="crm-page-title">{{ __('app.financial_report_title') }}</h1>
        <p class="crm-page-copy">{{ __('app.financial_report_copy') }}</p>
    </div>

    @include('accounts.finance._nav', ['financeSection' => 'financial'])

    @include('accounts.reports.finance._period-filter')

    @php
        $summaryCards = [
            ['key' => 'payments', 'label' => __('app.finance_received_payments')],
            ['key' => 'refunds', 'label' => __('app.finance_refunds')],
            ['key' => 'expenses', 'label' => __('app.finance_operating_expenses')],
            ['key' => 'owner_deposits', 'label' => __('app.finance_owner_deposits')],
            ['key' => 'owner_withdrawals', 'label' => __('app.finance_owner_withdrawals')],
            ['key' => 'operating_cash_result', 'label' => __('app.finance_operating_cash_result')],
        ];
    @endphp

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($summaryCards as $card)
            <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                <p class="text-sm font-semibold text-slate-500">{{ $card['label'] }}</p>
                <div class="mt-3">
                    @include('accounts.reports.finance._money-values', ['amounts' => $report['totals'][$card['key']]])
                </div>
                @if (in_array($card['key'], ['owner_deposits', 'owner_withdrawals'], true))
                    <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('app.finance_owner_movements_profit_notice') }}</p>
                @endif
            </article>
        @endforeach
    </section>

    @php
        $sections = [
            'payments' => __('app.finance_received_payments'),
            'refunds' => __('app.finance_refunds'),
            'expenses' => __('app.finance_operating_expenses'),
            'owner_deposits' => __('app.finance_owner_deposits'),
            'owner_withdrawals' => __('app.finance_owner_withdrawals'),
        ];
    @endphp

    <div class="mt-6 space-y-6">
        @foreach ($sections as $sectionKey => $sectionLabel)
            <x-ui.panel padding="none" class="overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-stone-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-lg font-semibold text-slate-950">{{ $sectionLabel }}</h2>
                    @include('accounts.reports.finance._money-values', ['amounts' => $report['totals'][$sectionKey]])
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-[720px] w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3">{{ __('app.date') }}</th>
                                <th class="px-5 py-3">{{ __('app.finance_entry') }}</th>
                                <th class="px-5 py-3">{{ __('app.location') }}</th>
                                <th class="px-5 py-3 text-right">{{ __('app.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($report['sections'][$sectionKey] as $row)
                                <tr class="align-top">
                                    <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-700">
                                        {{ \App\Support\DateTimePresenter::format($row['occurred_at'], $account) }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-slate-950">{{ $row['label'] ?: __('app.finance_entry') }}</div>
                                        @if ($row['details'])
                                            <div class="mt-1 max-w-xl text-slate-500">{{ $row['details'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-slate-600">{{ $row['location'] ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right font-semibold tabular-nums text-slate-950">
                                        {{ \App\Support\MoneyFormatter::format($row['amount_cents'], $row['currency']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center text-sm text-slate-500">{{ __('app.finance_no_entries_for_period') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.panel>
        @endforeach
    </div>
@endsection
