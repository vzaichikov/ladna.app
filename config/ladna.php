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
