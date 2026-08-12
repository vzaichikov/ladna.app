@extends('layouts.public', ['hideAppFooter' => true])

@section('title', __('app.festival_portal').' - '.$account->name)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')
        <header class="mt-8"><p class="text-sm font-semibold text-brand-700">{{ $account->name }}</p><h1 class="mt-1 text-3xl font-semibold sm:text-4xl">{{ __('app.festival_calendar') }}</h1><p class="mt-2 text-slate-600">{{ __('app.festival_portal_welcome', ['name' => $portalUser->displayName()]) }}</p></header>
        @if(session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif

        <section class="mt-7">
            <h2 class="text-2xl font-semibold">{{ __('app.festivals') }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                @forelse($editions as $edition)
                    <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm"><p class="text-sm font-semibold text-brand-700">{{ $edition->series->name }}</p><h3 class="mt-1 text-xl font-semibold">{{ $edition->title }}</h3><p class="mt-2 text-sm text-slate-500">{{ $edition->starts_at->timezone($edition->timezone)->format('d.m.Y H:i') }} · {{ $edition->venue_name }}</p><div class="mt-4 flex flex-wrap gap-2">@if($edition->registrationIsOpen())<x-ui.button :href="route('festival.portal.entries.create', [$account->slug, $edition->slug])">{{ __('app.festival_new_performance') }}</x-ui.button>@endif<x-ui.button :href="route('public.festivals.show', [$account->slug, $edition->slug])" variant="secondary">{{ __('app.more') }}</x-ui.button></div>@if($edition->scheduleSlots->isNotEmpty())<div class="mt-4 border-t border-stone-100 pt-4"><h4 class="text-sm font-semibold">{{ __('app.festival_personal_schedule') }}</h4>@foreach($edition->scheduleSlots as $slot)<p class="mt-2 text-sm">{{ $slot->starts_at->timezone($edition->timezone)->format('d.m H:i') }} · {{ $slot->stage->name }} · {{ $slot->entry->entry_name }}</p>@endforeach</div>@endif</article>
                @empty
                    <x-ui.empty-state icon="trophy">{{ __('app.festivals_public_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
        </section>

        <section class="mt-9">
            <h2 class="text-2xl font-semibold">{{ __('app.festival_my_performances') }}</h2>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                @forelse($entries as $entry)
                    @php $approvedSteps = $entry->steps->where('status', \App\Enums\FestivalEntryStepStatus::Approved)->count(); @endphp
                    <a href="{{ route('festival.portal.entries.show', [$account->slug, $entry]) }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm transition hover:border-brand-300"><div class="flex items-start justify-between gap-4"><div><span class="font-mono text-xs text-slate-500">{{ $entry->code }}</span><h3 class="mt-1 text-xl font-semibold">{{ $entry->entry_name }}</h3><p class="mt-1 text-sm text-slate-500">{{ $entry->edition->title }} · {{ $entry->category->name }}</p></div><span class="crm-status-muted">{{ __('app.festival_entry_status_'.$entry->status->value) }}</span></div><div class="mt-4"><div class="flex justify-between text-xs text-slate-500"><span>{{ __('app.festival_registration_progress') }}</span><span>{{ $approvedSteps }}/{{ $entry->steps->count() }}</span></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-600" style="width: {{ $entry->steps->isEmpty() ? 0 : round($approvedSteps / $entry->steps->count() * 100) }}%"></div></div></div></a>
                @empty
                    <x-ui.empty-state icon="trophy">{{ __('app.festival_entries_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
        </section>

        @if($notifications->isNotEmpty())<section class="mt-9"><h2 class="text-2xl font-semibold">{{ __('app.notifications') }}</h2><div class="mt-4 space-y-2">@foreach($notifications as $notification)<article class="rounded-xl border border-stone-200 bg-white p-4"><strong>{{ __('app.festival_notification_type_'.$notification->type->value) }}</strong><span class="ml-2 text-xs text-slate-500">{{ $notification->created_at->diffForHumans() }}</span></article>@endforeach</div></section>@endif
    </div>
</main>
@endsection
