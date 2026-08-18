@extends('layouts.festival-public')

@section('festivalContent')
@php
    $desktopHero = $edition->media->firstWhere('is_cover', true);
    $mobileHero = $edition->media->firstWhere('is_mobile_cover', true);
    $fallbackHero = $desktopHero ?? $mobileHero;
    $startsAt = $edition->starts_at->timezone($edition->timezone);
    $endsAt = $edition->ends_at->timezone($edition->timezone);
    $showImportantDates = $structuredContentSections->has('important-dates');
    $showJudges = $structuredContentSections->has('jury');
    $showStages = $structuredContentSections->has('stage');
    $showFees = $structuredContentSections->has('payments');
    $hasContentCards = $showImportantDates || $showJudges || $showStages || $showFees || $publicContentSections->isNotEmpty();
@endphp

<main id="festival-page-top" class="festival-velvet min-h-screen festival-page" tabindex="-1">
    <section class="velvet-hero">
        @if ($fallbackHero?->url())
            <picture class="velvet-hero-media">
                @if ($desktopHero?->url() && $mobileHero?->url())
                    <source media="(max-width: 767px)" srcset="{{ $mobileHero->url() }}">
                @endif
                <img
                    src="{{ $fallbackHero->url() }}"
                    alt="{{ $fallbackHero->alt_text ?: $edition->title }}"
                    class="velvet-hero-image"
                >
            </picture>
        @else
            <div class="velvet-hero-fallback" aria-hidden="true"></div>
        @endif

        <div class="velvet-hero-shade" aria-hidden="true"></div>

        <div class="velvet-hero-inner">
            <header class="velvet-header">
                <a href="{{ route('public.studio', $account->slug) }}" class="velvet-studio-link">
                    <span class="velvet-studio-mark">
                        <img src="{{ $account->logoUrl() }}" alt="" class="h-full w-full object-contain">
                    </span>
                    <span class="min-w-0">
                        <span class="velvet-studio-name">{{ $account->name }}</span>
                        @if ($account->studio_slogan)
                            <span class="velvet-studio-slogan">{{ $account->studio_slogan }}</span>
                        @endif
                    </span>
                </a>

                <nav class="velvet-nav" aria-label="{{ __('app.festival_landing_template') }}">
                    @if ($edition->description_html || $edition->sections->isNotEmpty())
                        <a href="#festival-information">{{ __('app.festival_details') }}</a>
                    @endif
                    @if ($edition->rules_html)
                        <a href="#festival-rules">{{ __('app.festival_rules') }}</a>
                    @endif
                    <a href="#festival-admission">{{ __('app.festival_admission') }}</a>
                </nav>
            </header>

            <div class="velvet-hero-content">
                <h1>{{ $edition->title }}</h1>
                @if ($edition->summary)
                    <p class="velvet-summary">{{ $edition->summary }}</p>
                @endif

                <div class="velvet-hero-meta" aria-label="{{ __('app.festival_overview_title') }}">
                    <span>{{ $startsAt->format('d.m.Y') }}</span>
                    <span aria-hidden="true">✦</span>
                    <span>{{ $startsAt->format('H:i') }}–{{ $endsAt->format('H:i') }}</span>
                    @if ($edition->venue_name)
                        <span aria-hidden="true">✦</span>
                        <span>{{ $edition->venue_name }}</span>
                    @endif
                </div>

                <div class="velvet-actions">
                    @if ($edition->registrationIsOpen())
                        <a href="{{ route('festival.login', $account->slug) }}" class="velvet-button velvet-button-primary">
                            {{ __('app.festival_apply') }}
                        </a>
                    @endif
                    <a href="#festival-admission" class="velvet-button velvet-button-outline">
                        {{ __('app.buy_tickets') }}
                    </a>
                </div>
            </div>

            <a href="#festival-information" class="velvet-scroll-link">
                <span aria-hidden="true"></span>
                {{ __('app.festival_details') }}
            </a>
        </div>
    </section>

    <section class="velvet-facts" aria-label="{{ __('app.festival_overview_title') }}">
        <div class="velvet-shell velvet-facts-grid">
            <div>
                <p>{{ __('app.date') }}</p>
                <strong>{{ $startsAt->format('d.m.Y') }}</strong>
            </div>
            <div>
                <p>{{ __('app.time') }}</p>
                <strong>{{ $startsAt->format('H:i') }}–{{ $endsAt->format('H:i') }}</strong>
            </div>
            <div>
                <p>{{ __('app.festival_venue') }}</p>
                <strong>{{ $edition->venue_name }}</strong>
                @if ($edition->venue_address)
                    <small>{{ $edition->venue_address }}</small>
                @endif
            </div>
        </div>
    </section>

    <div class="velvet-shell py-8 sm:py-12">
        @include('festivals.public._timeline')
    </div>

    <div class="velvet-shell velvet-body">
        <section id="festival-information" class="velvet-story">
            <div class="velvet-section-heading">
                <p>{{ $edition->series->name }}</p>
                <h2>{{ $edition->title }}</h2>
            </div>
            @if ($edition->description_html)
                <div class="prose festival-prose velvet-prose max-w-none">
                    {!! $edition->description_html !!}
                </div>
            @endif
        </section>

        @if ($hasContentCards)
            <section class="velvet-content-grid">
                @if ($showImportantDates)
                    <article class="velvet-card">
                        <h2>{{ $structuredContentSections->get('important-dates')->title }}</h2>
                        <div class="velvet-copy-list velvet-prose mt-5">
                            @foreach ($publicDates as $date)
                                <p>{{ $date['label'] }} — {{ $date['date'] }}.</p>
                            @endforeach
                        </div>
                    </article>
                @endif

                @if ($showJudges)
                    <article class="velvet-card">
                        <h2>{{ $structuredContentSections->get('jury')->title }}</h2>
                        <div class="prose festival-prose velvet-copy-list velvet-prose mt-5 max-w-none">
                            {!! $structuredContentSections->get('jury')->body_html !!}
                        </div>
                    </article>
                @endif

                @if ($showStages)
                    <article class="velvet-card">
                        <h2>{{ $structuredContentSections->get('stage')->title }}</h2>
                        <div class="velvet-copy-list velvet-prose mt-5">
                            @foreach ($publicStages as $stage)
                                <p>
                                    <strong>{{ $stage->name }}</strong>
                                    @if ($stage->description)
                                        <br>{{ $stage->description }}
                                    @endif
                                </p>
                            @endforeach
                        </div>
                    </article>
                @endif

                @if ($showFees)
                    <article class="velvet-card">
                        <h2>{{ $structuredContentSections->get('payments')->title }}</h2>
                        <div class="velvet-copy-list velvet-prose mt-5">
                            @foreach ($publicFees as $fee)
                                <p>
                                    <strong>{{ $fee->name }}</strong> — {{ \App\Support\MoneyFormatter::format($fee->amount_cents, $fee->currency) }}.@if ($fee->pricing_mode === \App\Enums\FestivalChargePricingMode::Roster && $fee->included_members !== null && $fee->additional_member_amount_cents !== null)
                                        {{ __('app.festival_public_roster_fee', [
                                            'count' => $fee->included_members,
                                            'amount' => \App\Support\MoneyFormatter::format($fee->additional_member_amount_cents, $fee->currency),
                                        ]) }}
                                    @endif
                                </p>
                            @endforeach
                        </div>
                    </article>
                @endif

                @foreach ($publicContentSections as $section)
                    <article class="velvet-card">
                        <h2>{{ $section->title }}</h2>
                        <div class="prose festival-prose velvet-prose mt-5 max-w-none">
                            {!! $section->body_html !!}
                        </div>
                    </article>
                @endforeach
            </section>
        @endif

        @if ($edition->rules_html)
            <section id="festival-rules" class="velvet-panel velvet-rules">
                <div class="velvet-panel-title">
                    <h2>{{ __('app.festival_rules') }}</h2>
                </div>
                <div class="prose festival-prose velvet-prose max-w-none">
                    {!! $edition->rules_html !!}
                </div>
            </section>
        @endif

        @if ($edition->results->isNotEmpty())
            <section class="velvet-panel">
                <div class="velvet-panel-title">
                    <h2>{{ __('app.festival_results') }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="velvet-table min-w-full text-sm">
                        <thead>
                            <tr>
                                <th class="px-3 py-3 text-left">#</th>
                                <th class="px-3 py-3 text-left">{{ __('app.performance') }}</th>
                                <th class="px-3 py-3 text-left">{{ __('app.festival_category') }}</th>
                                <th class="px-3 py-3 text-right">{{ __('app.festival_score') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($edition->results->sortBy('rank') as $result)
                                <tr>
                                    <td class="px-3 py-4 font-semibold">{{ $result->rank }}</td>
                                    <td class="px-3 py-4">{{ $result->entry->entry_name }}</td>
                                    <td class="px-3 py-4">{{ $result->entry->category->name }}</td>
                                    <td class="px-3 py-4 text-right">{{ $result->total_score }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <div class="velvet-section-heading mt-8">
            <p>{{ $edition->series->name }}</p>
            <h2>{{ __('app.festival_admission') }}</h2>
        </div>
        @include('festivals.public._admission-checkout')
    </div>
</main>

<button
    type="button"
    class="velvet-scroll-top"
    data-velvet-scroll-top
    data-visible="false"
    aria-label="{{ __('app.festival_back_to_top') }}"
    title="{{ __('app.festival_back_to_top') }}"
    hidden
>
    <x-ui.icon name="chevron-up" class="h-5 w-5" />
</button>
@endsection

@section('festivalFooterLinks')
    <a href="{{ route('festival.login', $account->slug) }}" class="velvet-footer-login">
        {{ __('app.festival_participant_cabinet') }}
    </a>
    <a href="{{ route('festival.judge.login', $account->slug) }}" class="velvet-footer-login">
        {{ __('app.festival_judge_cabinet') }}
    </a>
@endsection
