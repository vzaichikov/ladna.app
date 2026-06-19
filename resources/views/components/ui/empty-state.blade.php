@props([
    'title' => null,
    'icon' => 'sparkles',
])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center shadow-xs']) }}>
    <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700">
        <x-ui.icon :name="$icon" class="h-5 w-5" />
    </div>
    @if ($title)
        <h2 class="mt-4 text-lg font-semibold text-slate-950">{{ $title }}</h2>
    @endif
    <div class="mt-2 text-sm leading-6 text-slate-500">
        {{ $slot }}
    </div>
</div>
