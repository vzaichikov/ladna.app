@if ($privateLessons->isEmpty())
    <x-ui.empty-state :title="__('app.no_private_lessons_for_period')" icon="user" />
@else
    <div class="hidden gap-3 border-b border-stone-100 pb-3 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:grid lg:grid-cols-6">
        <div>{{ __('app.date_and_time') }}</div>
        <div>{{ __('app.class_type') }}</div>
        <div>{{ __('app.customer_section') }}</div>
        <div>{{ __('app.people_count') }}</div>
        <div>{{ __('app.location') }}</div>
        <div>{{ __('app.class_pass') }}@if ($canManageStudioCashflow) / {{ __('app.amount') }}@endif</div>
    </div>

    <div class="divide-y divide-stone-100">
        @foreach ($privateLessons as $privateLesson)
            @php
                $customer = $privateLesson['customer'];
                $classPass = $privateLesson['class_pass'];
            @endphp
            <article class="grid gap-4 py-4 text-sm lg:grid-cols-6 lg:items-start">
                <div>
                    <div class="font-semibold text-slate-950">{{ $privateLesson['date'] }}</div>
                    <div class="mt-1 text-slate-500">{{ $privateLesson['time'] }}</div>
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $privateLesson['class_type'] }}</div>
                    <div class="mt-1 text-slate-500">{{ $privateLesson['duration_minutes'] }} {{ __('app.minutes') }}</div>
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $customer?->name ?? $customer?->phone ?? __('app.not_set') }}</div>
                    @if ($customer?->phone && $customer?->name)
                        <div class="mt-1 text-slate-500">{{ $customer->phone }}</div>
                    @endif
                    @if ($privateLesson['booking_status'])
                        <span class="mt-2 inline-flex crm-status-muted">{{ __('app.'.$privateLesson['booking_status']) }}</span>
                    @endif
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $privateLesson['people_count'] }}</div>
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $privateLesson['location']?->name ?? __('app.not_set') }}</div>
                    @if ($privateLesson['room'])
                        <div class="mt-1 text-slate-500">{{ $privateLesson['room']->name }}</div>
                    @endif
                </div>
                <div>
                    @if ($classPass)
                        <div class="font-semibold text-slate-950">{{ $classPass->plan_name }}</div>
                        <div class="mt-1 text-slate-500">{{ $classPass->code }}</div>
                    @else
                        <div class="font-semibold text-amber-700">{{ __('app.class_pass_not_reserved') }}</div>
                    @endif
                    @if ($canManageStudioCashflow)
                        <div class="mt-2 font-semibold text-slate-950">
                            {{ $privateLesson['amount_cents'] === null
                                ? __('app.amount_not_specified')
                                : \App\Support\MoneyFormatter::format($privateLesson['amount_cents'], $privateLesson['currency'] ?? $account->default_currency) }}
                        </div>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    @if ($privateLessons->hasPages())
        <div class="mt-4" data-trainer-private-lessons-pagination>
            {{ $privateLessons->links() }}
        </div>
    @endif
@endif
