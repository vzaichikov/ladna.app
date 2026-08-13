@props(['account', 'edition'])

@php
    $cover = $edition->coverMedia;
    $coverUrl = $cover?->url();
    $startsAt = $edition->starts_at->timezone($edition->timezone);
    $endsAt = $edition->ends_at->timezone($edition->timezone);
    $dateLabel = $startsAt->isSameDay($endsAt)
        ? $startsAt->format('d.m.Y')
        : $startsAt->format('d.m.Y').'–'.$endsAt->format('d.m.Y');
    $timeLabel = $startsAt->format('H:i').'–'.$endsAt->format('H:i');
@endphp

<a
    href="{{ route('public.festivals.show', [$account->slug, $edition->slug]) }}"
    {{ $attributes->class('group flex h-full flex-col overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm transition hover:-translate-y-0.5 hover:border-brand-200') }}
    data-public-festival-card
>
    @if ($coverUrl)
        <img src="{{ $coverUrl }}" alt="{{ $cover->alt_text ?: $edition->title }}" class="aspect-[16/9] w-full object-cover">
    @else
        <span class="flex aspect-[16/9] w-full items-center justify-center bg-brand-50">
            <img src="{{ $account->logoUrl() }}" alt="" class="h-16 w-16 object-contain opacity-70">
        </span>
    @endif

    <span class="flex flex-1 flex-col p-5">
        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-brand-700">
            <time datetime="{{ $startsAt->toIso8601String() }}">{{ $dateLabel }}</time>
            <span aria-hidden="true">·</span>
            <span>{{ $timeLabel }}</span>
        </span>
        <span class="mt-2 block text-xl font-semibold leading-tight text-slate-950">{{ $edition->title }}</span>
        @if ($edition->summary)
            <span class="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">{{ $edition->summary }}</span>
        @endif
    </span>
</a>
