@props([
    'label',
    'variant' => 'account',
])

<span {{ $attributes->class([
    'inline-flex w-fit items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold',
    'border-sky-200 bg-sky-50 text-sky-700' => $variant === 'account',
    'border-violet-crm-200 bg-violet-crm-50 text-violet-crm-700' => $variant === 'location',
]) }}>
    <x-ui.icon :name="$variant === 'account' ? 'building-2' : 'locations'" class="h-3.5 w-3.5" />
    <span>{{ $label }}</span>
</span>
