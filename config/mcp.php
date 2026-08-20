<?php

$redirectDomains = array_values(array_filter(array_map(
    trim(...),
    explode(',', (string) env('MCP_OAUTH_REDIRECT_DOMAINS', 'https://chatgpt.com,https://claude.ai,https://claude.com')),
)));

if (env('APP_ENV', 'production') !== 'production') {
    $redirectDomains = [...$redirectDomains, 'http://localhost', 'http://127.0.0.1', 'http://[::1]'];
}

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Web OAuth clients may redirect only to these exact HTTPS origins. Native
    | apps may additionally use an HTTP loopback IP with an explicit port.
    | Paths below an allowed origin are accepted; wildcards are not.
    |
    */

    'redirect_domains' => $redirectDomains,

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Private-use redirect schemes stay disabled. Ladna accepts the official
    | HTTPS callback origins above and standards-based loopback IP callbacks.
    |
    */

    'custom_schemes' => [],

];
