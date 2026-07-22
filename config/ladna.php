<?php

return [
    'locales' => [
        'uk' => 'Українська',
        'en' => 'English',
    ],

    'currencies' => [
        'UAH',
        'USD',
        'EUR',
    ],

    'currency_symbols' => [
        'UAH' => '₴',
        'USD' => '$',
        'EUR' => '€',
    ],

    'saas_billing_v2_enabled' => env('LADNA_SAAS_BILLING_V2_ENABLED', false),

    'public_owner_onboarding_enabled' => env('LADNA_PUBLIC_OWNER_ONBOARDING_ENABLED', false),

    'public_owner_onboarding_turnstile_bypass' => env('LADNA_PUBLIC_OWNER_ONBOARDING_TURNSTILE_BYPASS', false),

    'countries' => [
        'UA' => 'Ukraine',
        'PL' => 'Poland',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'DE' => 'Germany',
        'FR' => 'France',
    ],

    'schedule_generation_weeks' => 2,
];
