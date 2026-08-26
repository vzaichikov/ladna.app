@extends('layouts.festival-portal')

@section('title', __('app.festival_portal').' - '.$account->name)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-6xl">
        @include('festivals.portal._nav')
        <header class="mt-8"><h1 class="text-3xl font-semibold sm:text-4xl">{{ __('app.festivals') }}</h1><p class="mt-2 text-slate-600">{{ __('app.festival_portal_welcome', ['name' => $portalUser->displayName()]) }}</p></header>
        @if(session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif

        <section class="mt-7">
            <div class="grid gap-4 md:grid-cols-2">
                @forelse($editions as $edition)
                    @php
                        $coverUrl = $edition->coverMedia?->url();
                        $applicationCount = (int) $edition->portal_entries_count;
                        $newApplicationUrl = route('festival.portal.entries.create', [$account->slug, $edition->slug]);
                    @endphp
                    <article class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
                        <a href="{{ route('public.festivals.show', [$account->slug, $edition->slug]) }}" class="relative block aspect-[16/9] overflow-hidden bg-[linear-gradient(135deg,#10233F_0%,#23405F_58%,#D9A441_145%)]">
                            @if($coverUrl)
                                <img src="{{ $coverUrl }}" alt="{{ $edition->coverMedia->alt_text ?: $edition->title }}" class="h-full w-full object-cover" loading="lazy">
                            @else
                                <span class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.22),transparent_38%)]"></span>
                                <span class="flex h-full items-center justify-center"><x-ui.icon name="trophy" class="h-16 w-16 text-amber-200/90" /></span>
                            @endif
                        </a>
                        <div class="p-5">
                            <p class="text-sm font-semibold text-brand-700">{{ $edition->series->name }}</p>
                            <h3 class="mt-1 text-xl font-semibold">{{ $edition->title }}</h3>
                            <p class="mt-2 text-sm text-slate-500">{{ $edition->starts_at->timezone($edition->timezone)->format('d.m.Y H:i') }} · {{ $edition->venue_name }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @if($edition->registrationIsOpen())
                                    @if($applicationCount > 0)
                                        <form
                                            method="GET"
                                            action="{{ $newApplicationUrl }}"
                                            data-confirm-action
                                            data-confirm-title="{{ __('app.festival_new_application') }}"
                                            data-confirm-body="{{ __('app.festival_additional_application_confirmation') }}"
                                            data-confirm-accept="{{ __('app.continue') }}"
                                            data-confirm-icon="plus"
                                            data-confirm-variant="primary"
                                        >
                                            <x-ui.button type="submit" variant="brand">{{ __('app.festival_new_application') }}</x-ui.button>
                                        </form>
                                    @else
                                        <x-ui.button :href="$newApplicationUrl" variant="success">{{ __('app.festival_new_application') }}</x-ui.button>
                                    @endif
                                @endif
                                <x-ui.button :href="route('festival.portal.entries.index', $account->slug)" variant="secondary">
                                    {{ __('app.festival_my_applications_count', ['count' => $applicationCount]) }}
                                </x-ui.button>
                                <x-ui.button :href="route('public.festivals.show', [$account->slug, $edition->slug])" variant="secondary">{{ __('app.more') }}</x-ui.button>
                            </div>
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state icon="trophy">{{ __('app.festivals_public_empty') }}</x-ui.empty-state>
                @endforelse
            </div>
        </section>

    </div>
</main>
@endsection
