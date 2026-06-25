@props([
    'border' => true,
])

<footer
    {{
        $attributes
            ->class([
                'flex flex-col items-center gap-2 text-center',
                'border-t border-stone-200 pt-6' => $border,
            ])
    }}
>
    <x-ui.app-logo mark-class="h-9 w-9" text-class="text-slate-950" />
    <div class="text-xs font-semibold tracking-[0.2em] text-slate-500">{{ __('app.powered_by_ladna') }}</div>
</footer>
