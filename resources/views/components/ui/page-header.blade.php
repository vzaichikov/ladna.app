@props([
    'title',
    'copy' => null,
])

<header {{ $attributes->class(['flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        <h1 class="crm-page-title">{{ $title }}</h1>
        @if (filled($copy))
            <p class="crm-page-copy">{{ $copy }}</p>
        @endif
        {{ $slot }}
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
            {{ $actions }}
        </div>
    @endisset
</header>
