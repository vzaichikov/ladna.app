@include('entrance.poster', [
    'occasion' => $festivalEdition,
    'qrDataUri' => $qrCode,
    'checkoutUrl' => $url,
    'occasionDateLabel' => $festivalEdition->starts_at?->timezone($festivalEdition->timezone)->format('d.m.Y H:i'),
    'venueLabel' => collect([$festivalEdition->venue_name, $festivalEdition->venue_address])->filter()->join(' · '),
])
