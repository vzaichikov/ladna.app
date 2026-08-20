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
    | OAuth clients may redirect only to these exact HTTPS origins. Paths below
    | an allowed origin are accepted, while wildcards and subdomains are not.
    |
    */

    'redirect_domains' => $redirectDomains,

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Private-use redirect schemes stay disabled. Ladna accepts the official
    | HTTPS callback origins above and local HTTP callbacks outside production.
    |
    */

    'custom_schemes' => [],

];
