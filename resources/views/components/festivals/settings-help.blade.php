@props(['title', 'description', 'dependencies' => []])

<aside {{ $attributes->class(['rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100']) }}>
    <div class="flex items-start gap-3">
        <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sm font-bold text-sky-700 dark:bg-sky-900 dark:text-sky-200">?</span>
        <div class="min-w-0">
            <h2 class="font-semibold">{{ $title }}</h2>
            <p class="mt-1 text-sm leading-6 text-sky-900/80 dark:text-sky-100/80">{{ $description }}</p>
            @if($dependencies)
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">{{ __('app.festival_dependencies') }}</p>
                <p class="mt-1 text-sm">{{ collect($dependencies)->join(' → ') }}</p>
            @endif
        </div>
    </div>
</aside>
