@include('entrance.poster', [
    'occasion' => $event,
    'qrDataUri' => $qrCode,
    'checkoutUrl' => $url,
    'occasionDateLabel' => $event->starts_at?->timezone($event->timezone)->format('d.m.Y H:i'),
    'venueLabel' => $event->venue_kind?->value === 'studio'
        ? collect([$event->location?->name, $event->location?->address])->filter()->join(' · ')
        : collect([$event->external_venue_name, $event->external_address])->filter()->join(' · '),
])
