@php
    $entranceTicketTypes = collect($ticketTypes)->map(fn ($ticketType): array => [
        'id' => $ticketType->id,
        'name' => $ticketType->name,
        'price_label' => \App\Support\MoneyFormatter::format($ticketType->price_cents, $festivalEdition->currency),
    ]);
    $entranceProviders = collect($paymentSettings)->map(fn ($setting): array => [
        'value' => $setting->provider->value,
        'label' => config('integrations.providers.'.$setting->provider->value.'.label', $setting->provider->value),
    ]);
@endphp

@include('entrance.public-checkout', [
    'occasion' => $festivalEdition,
    'ticketTypes' => $entranceTicketTypes,
    'paymentProviders' => $entranceProviders,
    'storeUrl' => route('public.festivals.entrance.store', [$account->slug, $festivalEdition->slug]),
])
