<?php

return [
    'sessions' => [
        'days' => 90,
    ],

    'google_oauth' => [
        'state_ttl_minutes' => 10,
        'login_code_ttl_minutes' => 5,
        'default_return_url' => env('LADNA_MOBILE_GOOGLE_RETURN_URL', 'https://ladna.app/mobile-auth/google'),
        'allowed_return_urls' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('LADNA_MOBILE_GOOGLE_ALLOWED_RETURN_URLS', env('LADNA_MOBILE_GOOGLE_RETURN_URL', 'https://ladna.app/mobile-auth/google')))
        ))),
    ],
];
