@props(['account', 'event'])

@php
    $cover = $event->media->firstWhere('is_cover', true);
    $startsAt = $event->starts_at->timezone($event->timezone);
    $endsAt = $event->ends_at->timezone($event->timezone);
    $timeLabel = $startsAt->isSameDay($endsAt)
        ? $startsAt->format('H:i').'–'.$endsAt->format('H:i')
        : $startsAt->format('H:i').' — '.$endsAt->format('d.m.Y H:i');
@endphp

<a
    href="{{ route('public.events.show', [$account->slug, $event->slug]) }}"
    {{ $attributes->class('group flex h-full flex-col overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm transition hover:-translate-y-0.5 hover:border-brand-200') }}
    data-public-event-card
>
    @if ($cover?->imageUrl())
        <img src="{{ $cover->imageUrl() }}" alt="{{ $cover->alt_text ?: $event->title }}" class="aspect-[16/9] w-full object-cover">
    @else
        <span class="flex aspect-[16/9] w-full items-center justify-center bg-brand-50">
            <img src="{{ $account->logoUrl() }}" alt="" class="h-16 w-16 object-contain opacity-70">
        </span>
    @endif

    <span class="flex flex-1 flex-col p-5">
        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-brand-700">
            <time datetime="{{ $startsAt->toIso8601String() }}">{{ $startsAt->format('d.m.Y') }}</time>
            <span aria-hidden="true">·</span>
            <span>{{ $timeLabel }}</span>
        </span>
        <span class="mt-2 block text-xl font-semibold leading-tight text-slate-950">{{ $event->title }}</span>
        @if ($event->summary)
            <span class="mt-2 line-clamp-3 text-sm leading-6 text-slate-500">{{ $event->summary }}</span>
        @endif
    </span>
</a>
