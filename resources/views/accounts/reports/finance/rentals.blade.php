@extends('layouts.app')

@section('title', __('app.rental_report_title').' - '.$account->name)

@section('content')
    <div>
        <h1 class="crm-page-title">{{ __('app.rental_report_title') }}</h1>
        <p class="crm-page-copy">{{ __('app.rental_report_copy') }}</p>
    </div>

    @include('accounts.finance._nav', ['financeSection' => 'rentals'])

    @include('accounts.reports.finance._period-filter')

    @php
        $summaryCards = [
            ['key' => 'accrued', 'label' => __('app.finance_accrued')],
            ['key' => 'paid', 'label' => __('app.finance_paid')],
            ['key' => 'refunded', 'label' => __('app.finance_refunded')],
            ['key' => 'debt', 'label' => __('app.finance_debt')],
        ];
    @endphp

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summaryCards as $card)
            <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                <p class="text-sm font-semibold text-slate-500">{{ $card['label'] }}</p>
                <div class="mt-3">
                    @include('accounts.reports.finance._money-values', ['amounts' => $report['totals'][$card['key']]])
                </div>
            </article>
        @endforeach
    </section>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-[1180px] w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('app.date') }}</th>
                        <th class="px-4 py-3">{{ __('app.location') }}</th>
                        <th class="px-4 py-3">{{ __('app.room') }}</th>
                        <th class="px-4 py-3">{{ __('app.finance_customer') }}</th>
                        <th class="px-4 py-3">{{ __('app.duration') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('app.finance_accrued') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('app.finance_paid') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('app.finance_refunded') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('app.finance_debt') }}</th>
                        <th class="px-4 py-3">{{ __('app.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($report['rows'] as $row)
                        @php
                            $statusClass = match ($row['status']) {
                                'paid' => 'crm-status-active',
                                'partially_paid' => 'crm-status-warning',
                                'refunded' => 'crm-status-muted',
                                default => 'crm-status-danger',
                            };
                        @endphp
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-slate-700">
                                {{ \App\Support\DateTimePresenter::format($row['starts_at'], $account) }}
                            </td>
                            <td class="px-4 py-4 text-slate-600">{{ $row['location']?->name ?? '—' }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ $row['room']?->name ?? '—' }}</td>
                            <td class="px-4 py-4 font-semibold text-slate-950">{{ $row['customer']?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-slate-600">{{ __('app.finance_minutes', ['count' => $row['duration_minutes']]) }}</td>
                            @foreach (['accrued', 'paid', 'refunded', 'debt'] as $amountKey)
                                <td class="px-4 py-4 text-right">
                                    @include('accounts.reports.finance._money-values', ['amounts' => $row[$amountKey.'_by_currency']])
                                </td>
                            @endforeach
                            <td class="px-4 py-4">
                                <span class="{{ $statusClass }}">{{ __('app.finance_rental_status_'.$row['status']) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-5 py-10 text-center text-sm text-slate-500">{{ __('app.finance_no_rentals_for_period') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.panel>
@endsection
