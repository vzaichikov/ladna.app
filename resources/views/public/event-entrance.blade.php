@php
    $entranceTicketTypes = collect($ticketTypes)->map(fn ($ticketType): array => [
        'id' => $ticketType->id,
        'name' => $ticketType->name,
        'price_label' => \App\Support\MoneyFormatter::format($ticketType->price_cents, $event->currency),
    ]);
    $entranceProviders = collect($paymentSettings)->map(fn ($setting): array => [
        'value' => $setting->provider->value,
        'label' => config('integrations.providers.'.$setting->provider->value.'.label', $setting->provider->value),
    ]);
@endphp

@include('entrance.public-checkout', [
    'occasion' => $event,
    'ticketTypes' => $entranceTicketTypes,
    'paymentProviders' => $entranceProviders,
    'storeUrl' => route('public.events.entrance.store', [$account->slug, $event->slug]),
])
