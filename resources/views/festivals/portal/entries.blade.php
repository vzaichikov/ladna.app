@extends('layouts.festival-portal')

@section('title', __('app.festival_my_performances').' - '.$account->name)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')
        <header class="mt-8"><h1 class="text-3xl font-semibold sm:text-4xl">{{ __('app.festival_my_performances') }}</h1></header>
        @if(session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif

        <section class="mt-7">
            <div class="grid gap-4 lg:grid-cols-2">
                @forelse($entries as $entry)
                    @php
                        $approvedSteps = $entry->steps->where('status', \App\Enums\FestivalEntryStepStatus::Approved)->count();
                        $coverUrl = $entry->edition->coverMedia?->url();
                        $currentStep = $entry->steps->first(fn ($step) => $step->status !== \App\Enums\FestivalEntryStepStatus::Approved);
                        $currentPaymentCharges = $currentStep?->charges
                            ->filter(fn ($charge) => $charge->amount_cents > 0 && $charge->status !== \App\Enums\FestivalChargeStatus::Cancelled)
                            ?? collect();
                        $currentStepIsPaid = $currentPaymentCharges->isNotEmpty()
                            && $currentPaymentCharges->every(fn ($charge) => $charge->status === \App\Enums\FestivalChargeStatus::Paid);
                    @endphp
                    <article class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm transition hover:border-brand-300">
                        <a href="{{ route('festival.portal.entries.show', [$account->slug, $entry]) }}" class="block">
                            <span class="relative block aspect-[16/9] overflow-hidden bg-[linear-gradient(135deg,#10233F_0%,#23405F_58%,#D9A441_145%)]">
                                @if($coverUrl)
                                    <img src="{{ $coverUrl }}" alt="{{ $entry->edition->coverMedia->alt_text ?: $entry->edition->title }}" class="h-full w-full object-cover" loading="lazy">
                                @else
                                    <span class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.22),transparent_38%)]"></span>
                                    <span class="flex h-full items-center justify-center"><x-ui.icon name="trophy" class="h-16 w-16 text-amber-200/90" /></span>
                                @endif
                            </span>
                            <span class="block p-5">
                                <span class="flex items-start justify-between gap-4">
                                    <span class="min-w-0">
                                        <span class="block font-mono text-xs text-slate-500">{{ $entry->code }}</span>
                                        <span class="mt-1 block text-xl font-semibold text-slate-950">{{ $entry->entry_name }}</span>
                                        <span class="mt-1 block text-sm text-slate-500">{{ $entry->edition->title }} · {{ $entry->category->name }}</span>
                                    </span>
                                    <span class="flex shrink-0 flex-col items-end gap-2">
                                        <span class="{{ $entry->status->badgeClass() }}">{{ __('app.festival_entry_status_'.$entry->status->value) }}</span>
                                        @if($currentPaymentCharges->isNotEmpty())
                                            <span class="{{ $currentStepIsPaid ? 'crm-status-active' : 'crm-status-danger' }}">
                                                {{ $currentStepIsPaid ? __('app.festival_charge_status_paid') : __('app.festival_application_payment_unpaid') }}
                                            </span>
                                        @endif
                                    </span>
                                </span>
                                <span class="mt-4 block">
                                    <span class="flex justify-between text-xs text-slate-500"><span>{{ __('app.festival_registration_progress') }}</span><span>{{ $approvedSteps }}/{{ $entry->steps->count() }}</span></span>
                                    <span class="mt-2 block h-2 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-brand-600" style="width: {{ $entry->steps->isEmpty() ? 0 : round($approvedSteps / $entry->steps->count() * 100) }}%"></span></span>
                                </span>
                                @if($entry->scheduleSlots->isNotEmpty())
                                    <span class="mt-4 block border-t border-stone-100 pt-4">
                                        <span class="block text-sm font-semibold text-slate-950">{{ __('app.festival_personal_schedule') }}</span>
                                        @foreach($entry->scheduleSlots as $slot)
                                            <span class="mt-2 block text-sm text-slate-600">{{ $slot->starts_at->timezone($entry->edition->timezone)->format('d.m H:i') }} · {{ $slot->stage->name }}</span>
                                        @endforeach
                                    </span>
                                @endif
                            </span>
                        </a>
                    </article>
                @empty
                    <x-ui.empty-state icon="trophy">{{ __('app.festival_entries_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
        </section>

        @if($notifications->isNotEmpty())<section class="mt-9"><h2 class="text-2xl font-semibold">{{ __('app.notifications') }}</h2><div class="mt-4 space-y-2">@foreach($notifications as $notification)<article class="rounded-xl border border-stone-200 bg-white p-4"><strong>{{ __('app.festival_notification_type_'.$notification->type->value) }}</strong><span class="ml-2 text-xs text-slate-500">{{ $notification->created_at->diffForHumans() }}</span></article>@endforeach</div></section>@endif
    </div>
</main>
@endsection
