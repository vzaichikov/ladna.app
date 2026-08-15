@extends('layouts.app')

@section('title', __('app.event_attendance').' - '.$event->title)

@section('content')
<div
    class="w-full min-w-0 space-y-6"
    data-event-admin-page
    data-event-attendance
    data-attendance-url="{{ route('dashboard.accounts.events.attendance.data', [$account, $event]) }}"
    data-poll-interval="5000"
>
    <x-ui.event-navigation :account="$account" :event="$event" active="attendance" />

    <div class="grid grid-cols-2 gap-2" data-attendance-stats>
        <div class="rounded-lg border border-stone-200 bg-white px-3 py-2 shadow-xs">
            <span class="text-xs font-semibold text-slate-500">{{ __('app.event_attendance_total') }}</span>
            <strong class="mt-0.5 block text-2xl leading-none text-slate-950" data-attendance-total>{{ $overview['total'] }}</strong>
        </div>
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 shadow-xs">
            <span class="text-xs font-semibold text-emerald-700">{{ __('app.event_attendance_passed') }}</span>
            <strong class="mt-0.5 block text-2xl leading-none text-emerald-950" data-attendance-passed>{{ $overview['passed'] }}</strong>
        </div>
    </div>

    <div class="grid grid-cols-[repeat(auto-fill,minmax(10rem,1fr))] gap-1.5" data-attendance-tickets>
        @foreach ($overview['tickets'] as $ticket)
            <div
                @class([
                    'min-w-0 rounded-lg border px-2 py-1.5 shadow-xs',
                    'border-emerald-200 bg-emerald-50 text-emerald-950' => $ticket['passed'],
                    'border-rose-200 bg-rose-50 text-rose-950' => ! $ticket['passed'],
                ])
                data-attendance-ticket="{{ $ticket['id'] }}"
                data-passed="{{ $ticket['passed'] ? 'true' : 'false' }}"
            >
                <div class="truncate text-xs font-semibold" data-attendance-customer>{{ $ticket['customer_name'] }}</div>
                <div class="truncate font-mono text-[10px] leading-tight opacity-75" data-attendance-code>{{ $ticket['code'] }}</div>
            </div>
        @endforeach
    </div>
</div>
@endsection
