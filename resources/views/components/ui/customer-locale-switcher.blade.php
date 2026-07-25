<nav
    {{ $attributes->class('inline-flex h-10 shrink-0 items-center rounded-lg border border-stone-200 bg-white p-1 shadow-xs') }}
    aria-label="{{ __('app.default_language') }}"
    data-customer-locale-switcher
>
    @foreach (array_keys(config('ladna.locales')) as $locale)
        <form method="POST" action="{{ route('locale.update') }}">
            @csrf
            <button
                type="submit"
                name="locale"
                value="{{ $locale }}"
                @class([
                    'inline-flex h-8 min-w-9 items-center justify-center rounded-md px-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-offset-1',
                    'bg-brand-700 text-white shadow-sm' => app()->getLocale() === $locale,
                    'text-slate-600 hover:bg-brand-50 hover:text-brand-700' => app()->getLocale() !== $locale,
                ])
                @if (app()->getLocale() === $locale) aria-current="true" @endif
            >
                {{ $locale === 'uk' ? 'UA' : strtoupper($locale) }}
            </button>
        </form>
    @endforeach
</nav>
