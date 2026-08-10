@props(['title', 'description', 'dependencies' => []])

<aside {{ $attributes->class(['rounded-2xl border border-brand-700 bg-brand-700 p-5 text-white shadow-crm']) }}>
    <div class="flex items-start gap-3">
        <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full border border-white/15 bg-white/10 text-sm font-bold text-brand-500">?</span>
        <div class="min-w-0">
            <h2 class="font-semibold">{{ $title }}</h2>
            <p class="mt-1 text-sm leading-6 text-white/80">{{ $description }}</p>
            @if($dependencies)
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-brand-500">{{ __('app.festival_dependencies') }}</p>
                <p class="mt-1 text-sm">{{ collect($dependencies)->join(' → ') }}</p>
            @endif
        </div>
    </div>
</aside>
