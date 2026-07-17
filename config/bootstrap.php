<?php

return [
    'enabled' => (bool) env('LADNA_PLATFORM_BOOTSTRAP_ENABLED', false),

    'platform_owner' => [
        'name' => env('LADNA_PLATFORM_OWNER_NAME', env('LADNA_DEMO_PLATFORM_NAME')),
        'email' => env('LADNA_PLATFORM_OWNER_EMAIL', env('LADNA_DEMO_PLATFORM_EMAIL')),
        'password' => env('LADNA_PLATFORM_OWNER_PASSWORD', env('LADNA_DEMO_PLATFORM_PASSWORD')),
    ],
];
