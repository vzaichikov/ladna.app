@props(['account'])

@php
    $supportLinks = $account->publicSupportLinks();
    $studioColor = is_string($account->brand_color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $account->brand_color)
        ? $account->brand_color
        : '#3B223F';
@endphp

@if ($supportLinks !== [])
    <section {{ $attributes->class('overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm')->merge(['style' => '--studio-brand-color: '.$studioColor]) }}>
        <div class="h-2" style="background-color: var(--studio-brand-color);"></div>
        <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
            <div>
                <h2 class="text-2xl font-semibold leading-tight text-slate-950">{{ __('app.public_contact_title', ['studio' => $account->name]) }}</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">{{ __('app.public_contact_copy') }}</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:flex lg:flex-wrap lg:justify-end">
                @foreach ($supportLinks as $supportLink)
                    @php
                        $opensInNewWindow = ! str_starts_with(strtolower($supportLink['url']), 'tel:');
                    @endphp

                    <a
                        href="{{ $supportLink['url'] }}"
                        @if ($opensInNewWindow)
                            target="_blank"
                            rel="noopener"
                        @endif
                        class="inline-flex min-h-12 items-center justify-center gap-2 rounded-lg border border-stone-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-800 transition hover:border-brand-100 hover:bg-brand-50 hover:text-brand-700"
                    >
                        <img src="{{ asset($supportLink['icon_path']) }}" alt="" class="h-5 w-5 shrink-0">
                        <span>{{ __($supportLink['label_key']) }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif
