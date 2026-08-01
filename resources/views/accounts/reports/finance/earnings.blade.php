@extends('layouts.app')

@section('title', __('app.earnings_report_title').' - '.$account->name)

@section('content')
    <div>
        <h1 class="crm-page-title">{{ __('app.earnings_report_title') }}</h1>
        <p class="crm-page-copy">{{ __('app.earnings_report_copy') }}</p>
    </div>

    @include('accounts.finance._nav', ['financeSection' => 'earnings'])

    @include('accounts.reports.finance._period-filter')

    @if ($report['salary_incomplete'])
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="font-semibold">{{ __('app.salary_report_incomplete') }}</div>
            <p class="mt-1 leading-6">{{ __('app.salary_report_incomplete_copy') }}</p>
        </div>
    @endif

    @php
        $summaryCards = [
            ['key' => 'lesson_revenue', 'label' => __('app.earnings_completed_lessons')],
            ['key' => 'rental_revenue', 'label' => __('app.earnings_completed_rentals')],
            ['key' => 'expenses', 'label' => __('app.finance_operating_expenses')],
            ['key' => 'salary', 'label' => __('app.earnings_accrued_salary')],
            ['key' => 'earnings', 'label' => __('app.earnings_operating_result')],
        ];
    @endphp

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
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
        <div class="border-b border-stone-100 px-5 py-4">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.earnings_completed_services') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('app.earnings_pass_allocation_notice') }}</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-[820px] w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-3">{{ __('app.date') }}</th>
                        <th class="px-5 py-3">{{ __('app.finance_service') }}</th>
                        <th class="px-5 py-3">{{ __('app.location') }}</th>
                        <th class="px-5 py-3">{{ __('app.finance_bookings_count') }}</th>
                        <th class="px-5 py-3 text-right">{{ __('app.finance_accrued') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($report['rows'] as $row)
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-700">
                                {{ \App\Support\DateTimePresenter::format($row['starts_at'], $account) }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="font-semibold text-slate-950">{{ $row['scheduled_class']->displayTitle() }}</div>
                                <div class="mt-1 text-slate-500">{{ __('app.'.$row['kind']) }}</div>
                            </td>
                            <td class="px-5 py-4 text-slate-600">
                                {{ collect([$row['location']?->name, $row['room']?->name])->filter()->join(' · ') ?: '—' }}
                            </td>
                            <td class="px-5 py-4 text-slate-600">{{ $row['bookings_count'] }}</td>
                            <td class="px-5 py-4 text-right">
                                <div class="justify-end">
                                    @include('accounts.reports.finance._money-values', ['amounts' => $row['value_by_currency']])
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-500">{{ __('app.finance_no_completed_services') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.panel>
@endsection
