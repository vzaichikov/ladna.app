@props(['sponsors', 'strip' => false])

@if ($sponsors->isNotEmpty())
    <section
        @if ($strip) data-festival-sponsors-strip @else data-festival-sponsors-section @endif
        {{ $attributes->class(['festival-sponsors', 'festival-sponsors--strip' => $strip]) }}
        aria-label="{{ __('app.festival_sponsors') }}"
    >
        <div class="festival-sponsors-heading">
            <h2 class="flex items-center gap-2">
                <x-ui.icon name="handshake" class="h-5 w-5 shrink-0" />
                {{ __('app.festival_sponsors') }}
            </h2>
            @unless ($strip)
                <p>{{ __('app.festival_sponsors_public_copy') }}</p>
            @endunless
        </div>

        <div class="festival-sponsors-list" @if ($strip) tabindex="0" role="group" aria-label="{{ __('app.festival_sponsors') }}" @endif>
            @foreach ($sponsors as $sponsor)
                <button
                    type="button"
                    class="festival-sponsor-card"
                    data-festival-sponsor-open="{{ $sponsor->id }}"
                    aria-haspopup="dialog"
                    aria-controls="festival-sponsor-{{ $sponsor->id }}"
                    aria-label="{{ __('app.festival_sponsor_details_for', ['name' => $sponsor->name]) }}"
                >
                    <span class="festival-sponsor-logo">
                        @if ($sponsor->logoUrl())
                            <img src="{{ $sponsor->logoUrl() }}" alt="" loading="lazy" width="240" height="120">
                        @else
                            <x-ui.icon name="handshake" class="h-10 w-10 text-slate-400" />
                        @endif
                    </span>
                    <span class="festival-sponsor-copy">
                        <strong>{{ $sponsor->name }}</strong>
                        <span class="festival-sponsor-introduction">{{ $sponsor->short_description }}</span>
                        @unless ($strip)
                            <span class="festival-sponsor-more">
                                {{ __('app.festival_sponsor_details') }}
                                <x-ui.icon name="arrow-up-right" class="h-4 w-4" />
                            </span>
                        @endunless
                    </span>
                </button>
            @endforeach
        </div>

        @foreach ($sponsors as $sponsor)
            @once('festival-sponsor-dialog-'.$sponsor->id)
                <dialog
                    id="festival-sponsor-{{ $sponsor->id }}"
                    class="festival-sponsor-dialog"
                    data-festival-sponsor-dialog
                    aria-labelledby="festival-sponsor-title-{{ $sponsor->id }}"
                >
                    <button type="button" class="festival-sponsor-close" data-festival-sponsor-close aria-label="{{ __('app.close') }}" autofocus>
                        <x-ui.icon name="x" class="h-5 w-5" />
                    </button>
                    <div class="festival-sponsor-dialog-body" tabindex="0" role="region" aria-labelledby="festival-sponsor-title-{{ $sponsor->id }}">
                        @if ($sponsor->logoUrl())
                            <div class="festival-sponsor-dialog-logo">
                                <img src="{{ $sponsor->logoUrl() }}" alt="" loading="lazy" width="240" height="120">
                            </div>
                        @endif
                        <p class="festival-sponsor-eyebrow">{{ __('app.festival_sponsor') }}</p>
                        <h2 id="festival-sponsor-title-{{ $sponsor->id }}">{{ $sponsor->name }}</h2>
                        <p class="festival-sponsor-dialog-introduction">{{ $sponsor->short_description }}</p>
                        @if (filled($sponsor->giveaway_description))
                            <div class="festival-sponsor-giveaway">
                                <h3><x-ui.icon name="gift" class="h-5 w-5 shrink-0" />{{ __('app.festival_sponsor_giveaway') }}</h3>
                                <p>{{ $sponsor->giveaway_description }}</p>
                            </div>
                        @endif
                        @if ($sponsor->url)
                            <a href="{{ $sponsor->url }}" target="_blank" rel="noopener noreferrer" class="festival-sponsor-website">
                                {{ __('app.festival_sponsor_visit_website') }}
                                <x-ui.icon name="external-link" class="h-4 w-4" />
                            </a>
                        @endif
                    </div>
                </dialog>
            @endonce
        @endforeach
    </section>
@endif
