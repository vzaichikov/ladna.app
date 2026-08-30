<?php

namespace App\Support\OpenApi;

class LadnaOpenApiSpec
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Ladna API',
                'version' => config('app.version', '1.0.0'),
                'description' => 'Public schedule, public prices, native mobile app, website lead intake, Festival battle operations and payment callbacks, and account-scoped MCP tools for Ladna studios.',
            ],
            'servers' => [
                [
                    'url' => url('/'),
                    'description' => 'Current Ladna installation',
                ],
            ],
            'tags' => [
                ['name' => 'Public schedule'],
                ['name' => 'Public prices'],
                ['name' => 'Mobile studio'],
                ['name' => 'Mobile auth'],
                ['name' => 'Mobile schedule'],
                ['name' => 'Mobile bookings'],
                ['name' => 'Mobile customer'],
                ['name' => 'Website leads'],
                ['name' => 'Festival payments'],
                ['name' => 'Festival battles'],
                ['name' => 'MCP'],
            ],
            'paths' => [
                '/api/v1/public/{accountSlug}/{locationSlug}/schedule' => $this->publicSchedulePath('Returns upcoming public group classes for a studio location.'),
                '/api/v1/public/{accountSlug}/{locationSlug}/schedule/week' => $this->publicScheduleWeekPath(),
                '/api/v1/public/{accountSlug}/{locationSlug}/classes' => $this->publicSchedulePath('Alias for the public schedule endpoint.'),
                '/api/v1/public/{accountSlug}/{locationSlug}/price' => $this->publicPricePath(),
                '/api/v1/mobile/studios/{accountSlug}' => $this->mobileStudioPath(),
                '/api/v1/mobile/auth/staff/login' => $this->mobileStaffLoginPath(),
                '/api/v1/mobile/auth/customer/email-login' => $this->mobileCustomerEmailLoginPath(),
                '/api/v1/mobile/auth/customer/otp/send' => $this->mobileCustomerOtpSendPath(),
                '/api/v1/mobile/auth/customer/otp/verify' => $this->mobileCustomerOtpVerifyPath(),
                '/api/v1/mobile/auth/customer/google/{accountSlug}/redirect' => $this->mobileGoogleRedirectPath(),
                '/api/v1/mobile/auth/customer/google/callback' => $this->mobileGoogleCallbackPath(),
                '/api/v1/mobile/auth/customer/google/exchange' => $this->mobileGoogleExchangePath(),
                '/api/v1/mobile/me' => $this->mobileMePath(),
                '/api/v1/mobile/logout' => $this->mobileLogoutPath(),
                '/api/v1/mobile/device-tokens' => $this->mobileDeviceTokenPath(),
                '/api/v1/mobile/schedule' => $this->mobileSchedulePath(),
                '/api/v1/mobile/classes/{scheduledClass}' => $this->mobileClassPath(),
                '/api/v1/mobile/classes/{scheduledClass}/customer-booking' => $this->mobileCustomerBookingPath(),
                '/api/v1/mobile/classes/{scheduledClass}/staff-bookings' => $this->mobileStaffBookingPath(),
                '/api/v1/mobile/bookings/{classBooking}' => $this->mobileBookingPath(),
                '/api/v1/mobile/customer/bookings' => $this->mobileCustomerBookingsPath(),
                '/api/v1/mobile/customer/passes' => $this->mobileCustomerPassesPath(),
                '/api/v1/mobile/customer/profile' => $this->mobileCustomerProfilePath(),
                '/api/v1/mobile/customer/profile/phone/send' => $this->mobileCustomerProfilePhoneOtpSendPath('Sends an OTP challenge to confirm a duplicate profile phone before merging customer identities.'),
                '/api/v1/mobile/customer/profile/phone/resend' => $this->mobileCustomerProfilePhoneOtpSendPath('Resends an OTP challenge for the pending duplicate profile phone confirmation.'),
                '/api/v1/mobile/customer/profile/phone/verify' => $this->mobileCustomerProfilePhoneOtpVerifyPath(),
                '/api/v1/mobile/staff/customers' => $this->mobileStaffCustomersPath(),
                '/api/v1/website-leads' => $this->websiteLeadPath(),
                '/api/v1/festival-payments/{provider}/callbacks' => $this->festivalPaymentCallbackPath(),
                '/api/v1/festival-battles/matches' => $this->festivalBattleMatchesPath(),
                '/api/v1/festival-battles/matches/{match}' => $this->festivalBattleMatchPath(),
                '/api/v1/festival-battles/matches/{match}/audience-score' => $this->festivalBattleAudienceScorePath(),
                '/mcp/ladna-studio' => $this->mcpStudioPath(),
                '/mcp/ladna-studio/{accountSlug}' => $this->mcpStudioOAuthPath(),
            ],
            'components' => [
                'securitySchemes' => [
                    'AccountBearerToken' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Ladna account API token',
                        'description' => 'Bearer token issued in studio settings. The issuing user must have the studio permission mapped to each selected ability. Website lead intake requires website_leads:create, Festival battle operations require festival_battles:operate, and MCP tools require their documented mcp:* abilities. Account scope always comes from this token; clients never submit an account identifier. Tokens remain account service credentials until revoked.',
                    ],
                    'MobileBearerToken' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Ladna native mobile session token',
                        'description' => 'Bearer token returned by mobile staff or customer authentication endpoints. It is scoped to one studio account and expires automatically.',
                    ],
                    'LadnaUserOAuth' => [
                        'type' => 'oauth2',
                        'description' => 'Studio staff sign in to Ladna and approve a read-only connection. Available tools are filtered by the user’s live studio permissions.',
                        'flows' => [
                            'authorizationCode' => [
                                'authorizationUrl' => route('passport.authorizations.authorize'),
                                'tokenUrl' => route('passport.token'),
                                'refreshUrl' => route('passport.token'),
                                'scopes' => [
                                    'mcp:use' => 'Use the connected Ladna studio tools allowed by the current user role.',
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => $this->responses(),
                'schemas' => $this->schemas(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function examples(): array
    {
        return [
            'public_schedule' => [
                'title' => __('app.api_docs_example_public_schedule'),
                'method' => 'GET',
                'path' => '/api/v1/public/{accountSlug}/{locationSlug}/schedule',
                'samples' => $this->codeSamples('GET', '/api/v1/public/ladna-demo/demo-location/schedule'),
            ],
            'public_price' => [
                'title' => __('app.api_docs_example_public_price'),
                'method' => 'GET',
                'path' => '/api/v1/public/{accountSlug}/{locationSlug}/price',
                'samples' => $this->codeSamples('GET', '/api/v1/public/ladna-demo/demo-location/price'),
            ],
            'website_lead' => [
                'title' => __('app.api_docs_example_website_lead'),
                'method' => 'POST',
                'path' => '/api/v1/website-leads',
                'samples' => $this->codeSamples('POST', '/api/v1/website-leads', [
                    'phone' => '+380671112233',
                    'name' => 'Олена Коваль',
                    'source_page' => 'https://studio.example.com/trial',
                ]),
            ],
            'festival_battle_matches' => [
                'title' => __('app.api_docs_example_festival_battle_matches'),
                'method' => 'GET',
                'path' => '/api/v1/festival-battles/matches',
                'samples' => $this->codeSamples('GET', '/api/v1/festival-battles/matches', null, true),
            ],
            'festival_battle_score' => [
                'title' => __('app.api_docs_example_festival_battle_score'),
                'method' => 'PUT',
                'path' => '/api/v1/festival-battles/matches/{match}/audience-score',
                'samples' => $this->codeSamples('PUT', '/api/v1/festival-battles/matches/42/audience-score', [
                    'audience_score_a' => 612345,
                    'audience_score_b' => 387655,
                    'measurement' => [
                        'metric' => 'baseline_adjusted_integrated_energy',
                        'baseline_duration_ms' => 2000,
                        'duration_ms' => 5000,
                        'baseline_dbfs' => -52.4,
                        'mean_dbfs_a' => -18.2,
                        'mean_dbfs_b' => -20.1,
                        'peak_dbfs_a' => -2.1,
                        'peak_dbfs_b' => -3.4,
                    ],
                ], true),
            ],
            'mcp_class_bookings' => [
                'title' => __('app.api_docs_example_mcp_class_bookings'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-class-bookings-for-day', [
                    'date' => '2026-06-30',
                ])),
            ],
            'mcp_customer_search' => [
                'title' => __('app.api_docs_example_mcp_customer_search'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('search-customers', [
                    'query' => 'Тетяна',
                    'limit' => 5,
                ])),
            ],
            'mcp_booking_investigation' => [
                'title' => __('app.api_docs_example_mcp_booking_investigation'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('investigate-customer-booking-ledger', [
                    'customer_id' => 63,
                    'from_date' => '2026-07-01',
                    'to_date' => '2026-07-31',
                    'as_of' => '2026-07-28T20:05:00+03:00',
                    'source' => 'manual',
                ])),
            ],
            'mcp_owner_help' => [
                'title' => __('app.api_docs_example_mcp_owner_help'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('search-owner-help', [
                    'query' => 'як додати клієнта',
                    'limit' => 3,
                ])),
            ],
            'mcp_describe_ladna_skills' => [
                'title' => __('app.api_docs_example_mcp_describe_ladna_skills'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('describe-ladna-skills', [
                    'channel' => 'dashboard_chat',
                ])),
            ],
            'mcp_payment_overview' => [
                'title' => __('app.api_docs_example_mcp_payment_overview'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-payment-overview', [
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-31',
                ])),
            ],
            'mcp_payment_search' => [
                'title' => __('app.api_docs_example_mcp_payment_search'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('search-payments', [
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-31',
                    'query' => 'Коваль',
                    'limit' => 20,
                ])),
            ],
            'mcp_financial_report' => [
                'title' => __('app.api_docs_example_mcp_financial_report'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-financial-report', [
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-31',
                    'limit' => 20,
                ])),
            ],
            'mcp_cashbox_overview' => [
                'title' => __('app.api_docs_example_mcp_cashbox_overview'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-cashbox-overview', [
                    'location_id' => 12,
                    'currency' => 'UAH',
                ])),
            ],
            'mcp_earnings_report' => [
                'title' => __('app.api_docs_example_mcp_earnings_report'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-earnings-report', [
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-31',
                ])),
            ],
            'mcp_rental_report' => [
                'title' => __('app.api_docs_example_mcp_rental_report'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-rental-report', [
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-31',
                ])),
            ],
            'mcp_payroll_overview' => [
                'title' => __('app.api_docs_example_mcp_payroll_overview'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-payroll-overview', [
                    'limit' => 20,
                ])),
            ],
            'mcp_events_overview' => [
                'title' => __('app.api_docs_example_mcp_events_overview'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-events-overview', [
                    'status_bucket' => 'upcoming',
                    'limit' => 20,
                ])),
            ],
            'mcp_event_summary' => [
                'title' => __('app.api_docs_example_mcp_event_summary'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-event-summary', [
                    'event_id' => 42,
                ])),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicSchedulePath(string $summary): array
    {
        return [
            'get' => [
                'tags' => ['Public schedule'],
                'summary' => $summary,
                'parameters' => $this->publicPathParameters(),
                'responses' => [
                    '200' => [
                        'description' => 'Upcoming public classes.',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => ['$ref' => '#/components/schemas/ScheduledClass'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    '402' => ['$ref' => '#/components/responses/SubscriptionExpired'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                ],
                'x-codeSamples' => $this->codeSamples('GET', '/api/v1/public/ladna-demo/demo-location/schedule'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicScheduleWeekPath(): array
    {
        return [
            'get' => [
                'tags' => ['Public schedule'],
                'summary' => 'Returns the exact public class schedule for one Monday-to-Sunday calendar week.',
                'parameters' => [
                    ...$this->publicPathParameters(),
                    [
                        'name' => 'date',
                        'in' => 'query',
                        'required' => false,
                        'description' => 'Any date inside the requested week. Defaults to today in the studio location timezone.',
                        'schema' => ['type' => 'string', 'format' => 'date'],
                        'example' => '2026-08-19',
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Seven schedule days and their concrete public classes.',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'minItems' => 7,
                                            'maxItems' => 7,
                                            'items' => ['$ref' => '#/components/schemas/PublicScheduleDay'],
                                        ],
                                        'meta' => ['$ref' => '#/components/schemas/PublicScheduleWeekMeta'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    '402' => ['$ref' => '#/components/responses/SubscriptionExpired'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                ],
                'x-codeSamples' => $this->codeSamples('GET', '/api/v1/public/ladna-demo/demo-location/schedule/week?date=2026-08-19'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPricePath(): array
    {
        return [
            'get' => [
                'tags' => ['Public prices'],
                'summary' => 'Returns active public price groups for a studio location.',
                'parameters' => $this->publicPathParameters(),
                'responses' => [
                    '200' => [
                        'description' => 'Grouped price list.',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => ['$ref' => '#/components/schemas/PriceGroup'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    '402' => ['$ref' => '#/components/responses/SubscriptionExpired'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
                'x-codeSamples' => $this->codeSamples('GET', '/api/v1/public/ladna-demo/demo-location/price'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileStudioPath(): array
    {
        return [
            'get' => [
                'tags' => ['Mobile studio'],
                'summary' => 'Returns public studio metadata and customer authentication methods for the native mobile app.',
                'parameters' => [$this->accountSlugParameter()],
                'responses' => [
                    '200' => $this->jsonDataResponse('Studio metadata and mobile customer auth options.', ['$ref' => '#/components/schemas/MobileStudio']),
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileStaffLoginPath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Authenticates a studio owner or staff member and returns one mobile session token per accessible studio account.',
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileStaffLoginRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Authenticated staff actor and account-scoped tokens.', ['$ref' => '#/components/schemas/MobileStaffLoginResponse']),
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileCustomerEmailLoginPath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Authenticates or creates a studio customer with email and password, then returns a mobile customer session.',
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileCustomerEmailLoginRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Authenticated customer session.', ['$ref' => '#/components/schemas/MobileCustomerSessionResponse']),
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileCustomerOtpSendPath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Sends an OTP challenge for customer phone login when the studio customer auth settings allow OTP.',
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileCustomerOtpSendRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('OTP challenge send result.', ['$ref' => '#/components/schemas/MobileCustomerOtpSendResponse']),
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileCustomerOtpVerifyPath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Verifies a customer OTP challenge and returns a mobile customer session.',
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileCustomerOtpVerifyRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Authenticated customer session.', ['$ref' => '#/components/schemas/MobileCustomerSessionResponse']),
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileGoogleRedirectPath(): array
    {
        return [
            'get' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Starts Google OAuth for a customer and redirects to Google.',
                'parameters' => [
                    $this->accountSlugParameter(),
                    [
                        'name' => 'return_url',
                        'in' => 'query',
                        'required' => false,
                        'description' => 'Optional mobile return URL. Must exactly match one of the configured mobile Google OAuth return URLs.',
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'responses' => [
                    '302' => ['description' => 'Redirect to Google OAuth.'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileGoogleCallbackPath(): array
    {
        return [
            'get' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Receives the Google OAuth callback and redirects to the mobile return URL with a one-time exchange code.',
                'responses' => [
                    '302' => ['description' => 'Redirect to the mobile return URL with code and account_slug query parameters, or an error query parameter.'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileGoogleExchangePath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Exchanges a one-time Google mobile login code for a customer mobile session.',
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileGoogleExchangeRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Authenticated customer session.', ['$ref' => '#/components/schemas/MobileCustomerSessionResponse']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileMePath(): array
    {
        return [
            'get' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Returns the current mobile session profile, account, actor, and permissions.',
                'security' => $this->mobileSecurity(),
                'responses' => [
                    '200' => $this->jsonDataResponse('Current mobile profile.', ['$ref' => '#/components/schemas/MobileProfile']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileLogoutPath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Revokes the current mobile session token.',
                'security' => $this->mobileSecurity(),
                'responses' => [
                    '200' => $this->messageResponse('Session revoked.'),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileDeviceTokenPath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile auth'],
                'summary' => 'Registers or updates a mobile push notification token for the current mobile session.',
                'security' => $this->mobileSecurity(),
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileDeviceTokenRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Registered device token metadata.', ['$ref' => '#/components/schemas/MobileDeviceTokenResponse']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileSchedulePath(): array
    {
        return [
            'get' => [
                'tags' => ['Mobile schedule'],
                'summary' => 'Returns account-scoped scheduled classes for the mobile actor. Customer sessions see public group classes only; trainer sessions are narrowed to the linked trainer.',
                'security' => $this->mobileSecurity(),
                'parameters' => [
                    ['name' => 'from', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'to', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'location_id', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                ],
                'responses' => [
                    '200' => $this->jsonDataResponse('Scheduled classes.', ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/MobileScheduledClass']]),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileClassPath(): array
    {
        return [
            'get' => [
                'tags' => ['Mobile schedule'],
                'summary' => 'Returns one account-scoped scheduled class for the mobile actor.',
                'security' => $this->mobileSecurity(),
                'parameters' => [$this->scheduledClassParameter()],
                'responses' => [
                    '200' => $this->jsonDataResponse('Scheduled class.', ['$ref' => '#/components/schemas/MobileScheduledClass']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileCustomerBookingPath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile bookings'],
                'summary' => 'Books the current customer into a public group class.',
                'security' => $this->mobileSecurity(),
                'parameters' => [$this->scheduledClassParameter()],
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileCustomerBookingRequest', false),
                'responses' => [
                    '201' => $this->jsonDataResponse('Created booking.', ['$ref' => '#/components/schemas/MobileClassBooking']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileStaffBookingPath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile bookings'],
                'summary' => 'Creates or reactivates a booking for a selected customer. Requires the manage_bookings studio permission.',
                'security' => $this->mobileSecurity(),
                'parameters' => [$this->scheduledClassParameter()],
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileStaffBookingRequest'),
                'responses' => [
                    '201' => $this->jsonDataResponse('Created booking.', ['$ref' => '#/components/schemas/MobileClassBooking']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileBookingPath(): array
    {
        return [
            'patch' => [
                'tags' => ['Mobile bookings'],
                'summary' => 'Updates booking status from a staff session. Setting cancelled before the cutoff releases the class-pass session and keeps the booking in history.',
                'security' => $this->mobileSecurity(),
                'parameters' => [$this->classBookingParameter()],
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileBookingStatusRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Updated booking.', ['$ref' => '#/components/schemas/MobileClassBooking']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
            'delete' => [
                'tags' => ['Mobile bookings'],
                'summary' => 'Cancels a booking before the cutoff and releases the class-pass session while keeping the booking in history. Staff need manage_bookings.',
                'security' => $this->mobileSecurity(),
                'parameters' => [$this->classBookingParameter()],
                'responses' => [
                    '200' => $this->jsonDataResponse('Cancelled booking.', ['$ref' => '#/components/schemas/MobileClassBooking']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileCustomerBookingsPath(): array
    {
        return [
            'get' => [
                'tags' => ['Mobile customer'],
                'summary' => 'Returns the current customer booking history for the session account.',
                'security' => $this->mobileSecurity(),
                'responses' => [
                    '200' => $this->jsonDataResponse('Customer bookings.', ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/MobileClassBooking']]),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileCustomerPassesPath(): array
    {
        return [
            'get' => [
                'tags' => ['Mobile customer'],
                'summary' => 'Returns the current customer class passes for the session account.',
                'security' => $this->mobileSecurity(),
                'responses' => [
                    '200' => $this->jsonDataResponse('Customer class passes.', ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/MobileCustomerClassPass']]),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileCustomerProfilePath(): array
    {
        return [
            'put' => [
                'tags' => ['Mobile customer'],
                'summary' => 'Updates the current customer profile for the session account. If the phone belongs to another customer in this studio, returns a validation response with code phone_verification_required.',
                'security' => $this->mobileSecurity(),
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileCustomerProfileUpdateRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Updated customer profile.', ['$ref' => '#/components/schemas/MobileCustomer']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileCustomerProfilePhoneOtpSendPath(string $summary): array
    {
        return [
            'post' => [
                'tags' => ['Mobile customer'],
                'summary' => $summary,
                'security' => $this->mobileSecurity(),
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileCustomerProfilePhoneOtpSendRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('OTP challenge send result.', ['$ref' => '#/components/schemas/MobileCustomerOtpSendResponse']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileCustomerProfilePhoneOtpVerifyPath(): array
    {
        return [
            'post' => [
                'tags' => ['Mobile customer'],
                'summary' => 'Verifies a duplicate profile phone OTP, merges the temporary email or Google identity into the existing phone customer, and returns the refreshed customer session.',
                'security' => $this->mobileSecurity(),
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileCustomerProfilePhoneOtpVerifyRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Merged customer session.', ['$ref' => '#/components/schemas/MobileCustomerSessionResponse']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mobileStaffCustomersPath(): array
    {
        return [
            'get' => [
                'tags' => ['Mobile customer'],
                'summary' => 'Searches account customers for staff booking flows. Requires manage_bookings or manage_clients.',
                'security' => $this->mobileSecurity(),
                'parameters' => [
                    [
                        'name' => 'q',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'responses' => [
                    '200' => $this->jsonDataResponse('Matching customers.', ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/MobileCustomer']]),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteLeadPath(): array
    {
        return [
            'post' => [
                'tags' => ['Website leads'],
                'summary' => 'Creates a website lead for the studio identified by the bearer token with the website_leads:create ability.',
                'security' => [
                    ['AccountBearerToken' => []],
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/WebsiteLeadRequest'],
                            'examples' => [
                                'trial_request' => [
                                    'value' => [
                                        'phone' => '+380671112233',
                                        'name' => 'Олена Коваль',
                                        'source_page' => 'https://studio.example.com/trial',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '201' => [
                        'description' => 'Lead created.',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => ['$ref' => '#/components/schemas/WebsiteLead'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '402' => ['$ref' => '#/components/responses/SubscriptionExpired'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
                'x-codeSamples' => $this->codeSamples('POST', '/api/v1/website-leads', [
                    'phone' => '+380671112233',
                    'name' => 'Олена Коваль',
                    'source_page' => 'https://studio.example.com/trial',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function festivalPaymentCallbackPath(): array
    {
        return [
            'post' => [
                'tags' => ['Festival payments'],
                'summary' => 'Receives a payment provider callback for a Festival performance charge or spectator admission order.',
                'description' => 'This endpoint is called by the configured payment provider. The provider adapter validates its signature and resolves the account from the opaque Festival order identifier. Processing is idempotent and rejects unknown providers, accounts with Festivals disabled, amount or currency mismatches, and invalid signatures.',
                'security' => [],
                'parameters' => [
                    [
                        'name' => 'provider',
                        'in' => 'path',
                        'required' => true,
                        'description' => 'Configured Ladna payment provider key.',
                        'schema' => ['type' => 'string', 'example' => 'monopay'],
                    ],
                ],
                'requestBody' => [
                    'required' => true,
                    'description' => 'Provider-specific signed callback payload. Fields vary by the configured gateway.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                            'example' => [
                                'reference' => 'provider_order_reference',
                                'status' => 'success',
                            ],
                        ],
                        'application/x-www-form-urlencoded' => [
                            'schema' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Provider-specific acknowledgement. Repeated successful callbacks are acknowledged without duplicating payment or ticket state.',
                        'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                    ],
                    '400' => [
                        'description' => 'The callback signature, order identifier, amount, currency, or state is invalid.',
                        'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                    ],
                    '404' => [
                        'description' => 'The provider, Festival order, enabled account capability, or payment integration was not found.',
                        'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                    ],
                    '500' => [
                        'description' => 'The callback could not be processed and may be retried safely.',
                        'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                    ],
                ],
                'x-codeSamples' => $this->codeSamples('POST', '/api/v1/festival-payments/monopay/callbacks', [
                    'reference' => 'provider_order_reference',
                    'status' => 'success',
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function festivalBattleMatchesPath(): array
    {
        return [
            'get' => [
                'tags' => ['Festival battles'],
                'summary' => 'Lists ready knockout matches from published or in-progress Festival editions.',
                'description' => 'Requires an account API token with festival_battles:operate. The token supplies the account scope. Only safe aggregate competition fields are returned; applicant contacts, credentials, judge identities, and individual judge votes are never exposed.',
                'security' => [['AccountBearerToken' => []]],
                'responses' => [
                    '200' => $this->festivalBattleResponse(true),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '402' => ['$ref' => '#/components/responses/SubscriptionExpired'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
                'x-codeSamples' => $this->codeSamples('GET', '/api/v1/festival-battles/matches', null, true),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function festivalBattleMatchPath(): array
    {
        return [
            'get' => [
                'tags' => ['Festival battles'],
                'summary' => 'Returns the current aggregate state of one ready or completed Festival battle.',
                'security' => [['AccountBearerToken' => []]],
                'parameters' => [$this->festivalBattleMatchParameter()],
                'responses' => [
                    '200' => $this->festivalBattleResponse(),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '402' => ['$ref' => '#/components/responses/SubscriptionExpired'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
                'x-codeSamples' => $this->codeSamples('GET', '/api/v1/festival-battles/matches/42', null, true),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function festivalBattleAudienceScorePath(): array
    {
        return [
            'put' => [
                'tags' => ['Festival battles'],
                'summary' => 'Idempotently records the normalized applause score and finalizes when all four judge votes permit it.',
                'description' => 'The two integer scores must total exactly 1,000,000. Audio is never accepted or stored. Repeat the identical PUT every five seconds after a 202 response. Ladna returns 202 while judges are incomplete or an exact 50/50 tie requires a manager jury decision, 200 after official completion, and 409 for a conflicting retry.',
                'security' => [['AccountBearerToken' => []]],
                'parameters' => [$this->festivalBattleMatchParameter()],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/FestivalBattleAudienceScoreRequest'],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => $this->festivalBattleResponse(),
                    '202' => $this->festivalBattleResponse(),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '402' => ['$ref' => '#/components/responses/SubscriptionExpired'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '409' => [
                        'description' => 'A different score is already stored for the match.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FestivalBattleConflictResponse']]],
                    ],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '426' => [
                        'description' => 'HTTPS is required outside local and test environments.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ErrorResponse']]],
                    ],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
                'x-codeSamples' => $this->codeSamples('PUT', '/api/v1/festival-battles/matches/42/audience-score', [
                    'audience_score_a' => 612345,
                    'audience_score_b' => 387655,
                    'measurement' => [
                        'metric' => 'baseline_adjusted_integrated_energy',
                        'baseline_duration_ms' => 2000,
                        'duration_ms' => 5000,
                    ],
                ], true),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function festivalBattleResponse(bool $collection = false): array
    {
        return [
            'description' => 'Safe Festival battle aggregate.',
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => $collection
                                ? ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FestivalBattleMatch']]
                                : ['$ref' => '#/components/schemas/FestivalBattleMatch'],
                            'meta' => ['$ref' => '#/components/schemas/FestivalBattleMeta'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function festivalBattleMatchParameter(): array
    {
        return [
            'name' => 'match',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer', 'minimum' => 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mcpStudioPath(): array
    {
        return [
            'post' => [
                'tags' => ['MCP'],
                'summary' => 'Calls Ladna studio MCP tools through JSON-RPC in the bearer token account scope.',
                'description' => 'The endpoint is not public. It requires a Ladna account API bearer token. Each tool checks its own ability, including mcp:read, mcp:customers:read, mcp:class-passes:read, mcp:payments:read, mcp:cashflow:read, mcp:payroll:read, mcp:events:read, mcp:bookings:create, mcp:bookings:cancel, or mcp:logic:read. Payment and financial-report abilities require view_studio_financial_reports; cashbox reads require manage_studio_cashflow; payroll reads require manage_studio_payroll; event abilities require manage_events. Customer booking-ledger investigation requires both mcp:customers:read and mcp:class-passes:read. Tool calls never accept account_id or tenant_id arguments for scoping. Read tools remain available for a read-only demo; mutation abilities return HTTP 423.',
                'security' => [
                    ['AccountBearerToken' => []],
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/McpToolCallRequest'],
                            'examples' => [
                                'class_bookings_for_day' => [
                                    'value' => $this->mcpToolCallBody('get-class-bookings-for-day', [
                                        'date' => '2026-06-30',
                                    ]),
                                ],
                                'owner_help_search' => [
                                    'value' => $this->mcpToolCallBody('search-owner-help', [
                                        'query' => 'як додати клієнта',
                                        'limit' => 3,
                                    ]),
                                ],
                                'customer_search' => [
                                    'value' => $this->mcpToolCallBody('search-customers', [
                                        'query' => 'Кіслань',
                                        'limit' => 5,
                                    ]),
                                ],
                                'customer_booking_investigation' => [
                                    'value' => $this->mcpToolCallBody('investigate-customer-booking-ledger', [
                                        'customer_id' => 63,
                                        'from_date' => '2026-07-01',
                                        'to_date' => '2026-07-31',
                                        'as_of' => '2026-07-28T20:05:00+03:00',
                                        'source' => 'manual',
                                    ]),
                                ],
                                'describe_ladna_skills' => [
                                    'value' => $this->mcpToolCallBody('describe-ladna-skills', [
                                        'channel' => 'dashboard_chat',
                                    ]),
                                ],
                                'payment_overview' => [
                                    'value' => $this->mcpToolCallBody('get-payment-overview', [
                                        'date_from' => '2026-07-01',
                                        'date_to' => '2026-07-31',
                                    ]),
                                ],
                                'payment_search' => [
                                    'value' => $this->mcpToolCallBody('search-payments', [
                                        'date_from' => '2026-07-01',
                                        'date_to' => '2026-07-31',
                                        'query' => 'Коваль',
                                        'limit' => 20,
                                    ]),
                                ],
                                'financial_report' => [
                                    'value' => $this->mcpToolCallBody('get-financial-report', [
                                        'date_from' => '2026-07-01',
                                        'date_to' => '2026-07-31',
                                        'limit' => 20,
                                    ]),
                                ],
                                'cashbox_overview' => [
                                    'value' => $this->mcpToolCallBody('get-cashbox-overview', [
                                        'location_id' => 12,
                                        'currency' => 'UAH',
                                    ]),
                                ],
                                'earnings_report' => [
                                    'value' => $this->mcpToolCallBody('get-earnings-report', [
                                        'date_from' => '2026-07-01',
                                        'date_to' => '2026-07-31',
                                    ]),
                                ],
                                'rental_report' => [
                                    'value' => $this->mcpToolCallBody('get-rental-report', [
                                        'date_from' => '2026-07-01',
                                        'date_to' => '2026-07-31',
                                    ]),
                                ],
                                'payroll_overview' => [
                                    'value' => $this->mcpToolCallBody('get-payroll-overview', [
                                        'limit' => 20,
                                    ]),
                                ],
                                'events_overview' => [
                                    'value' => $this->mcpToolCallBody('get-events-overview', [
                                        'status_bucket' => 'upcoming',
                                        'limit' => 20,
                                    ]),
                                ],
                                'event_summary' => [
                                    'value' => $this->mcpToolCallBody('get-event-summary', [
                                        'event_id' => 42,
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'JSON-RPC response. Tool-level ability denial is returned as a JSON-RPC error result.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/McpToolCallResponse'],
                            ],
                        ],
                    ],
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '423' => ['$ref' => '#/components/responses/DemoReadOnly'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
                'x-codeSamples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-class-bookings-for-day', [
                    'date' => '2026-06-30',
                ])),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mcpStudioOAuthPath(): array
    {
        $path = $this->mcpStudioPath();
        $path['post']['summary'] = 'Calls read-only Ladna studio MCP tools as a signed-in studio staff member.';
        $path['post']['description'] = 'OAuth authorization binds the client to the accountSlug studio. Every request rechecks the direct studio membership, role, and live StudioPermission values. Event-only staff are excluded. The tool list is filtered to allowed read tools, and this endpoint does not register mutation tools.';
        $path['post']['parameters'] = [$this->accountSlugParameter()];
        $path['post']['security'] = [['LadnaUserOAuth' => ['mcp:use']]];
        $path['post']['responses']['403'] = ['$ref' => '#/components/responses/Forbidden'];
        unset($path['post']['x-codeSamples']);

        return $path;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function publicPathParameters(): array
    {
        return [
            [
                'name' => 'accountSlug',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
                'example' => 'ladna-demo',
            ],
            [
                'name' => 'locationSlug',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
                'example' => 'demo-location',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountSlugParameter(): array
    {
        return [
            'name' => 'accountSlug',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
            'example' => 'ladna-demo',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledClassParameter(): array
    {
        return [
            'name' => 'scheduledClass',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
            'example' => 42,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classBookingParameter(): array
    {
        return [
            'name' => 'classBooking',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
            'example' => 101,
        ];
    }

    /**
     * @return array<int, array<string, array<int, mixed>>>
     */
    private function mobileSecurity(): array
    {
        return [
            ['MobileBearerToken' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonRequestBody(string $schemaRef, bool $required = true): array
    {
        return [
            'required' => $required,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => $schemaRef],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function jsonDataResponse(string $description, array $schema): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => $schema,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messageResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(): array
    {
        return [
            'Unauthorized' => [
                'description' => 'Bearer token is missing or invalid.',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'NotFound' => [
                'description' => 'Account, location, or resource was not found.',
            ],
            'Forbidden' => [
                'description' => 'The authenticated actor is not allowed to perform this action.',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'SubscriptionExpired' => [
                'description' => 'The studio SaaS subscription is unavailable, so public content is unavailable.',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string'],
                                'code' => [
                                    'type' => 'string',
                                    'enum' => ['subscription_expired', 'demo_payment_required'],
                                    'example' => 'subscription_expired',
                                ],
                                'support_url' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                ],
            ],
            'DemoReadOnly' => [
                'description' => 'The selected studio is a synthetic read-only demo and does not accept mutations.',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string'],
                                'code' => [
                                    'type' => 'string',
                                    'enum' => ['demo_readonly'],
                                    'example' => 'demo_readonly',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'ValidationError' => [
                'description' => 'Request validation failed.',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string'],
                                'code' => ['type' => ['string', 'null'], 'example' => 'phone_verification_required'],
                                'errors' => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
            ],
            'TooManyRequests' => [
                'description' => 'Rate limit exceeded.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        return [
            'MobileStudio' => [
                'type' => 'object',
                'properties' => [
                    'account' => ['$ref' => '#/components/schemas/MobileAccount'],
                    'customer_auth' => [
                        'type' => 'object',
                        'properties' => [
                            'email_password' => ['type' => 'boolean'],
                            'otp' => ['type' => 'boolean'],
                            'google' => ['type' => 'boolean'],
                            'turnstile_site_key' => ['type' => ['string', 'null']],
                            'google_redirect_url' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'MobileAccount' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'default_language' => ['type' => 'string'],
                    'country_code' => ['type' => ['string', 'null']],
                    'currency' => ['type' => ['string', 'null']],
                    'timezone' => ['type' => ['string', 'null']],
                    'brand_color' => ['type' => ['string', 'null']],
                    'logo_url' => ['type' => 'string'],
                    'slogan' => ['type' => ['string', 'null']],
                    'locations' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/MobileLocation'],
                    ],
                ],
            ],
            'MobileLocation' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'address' => ['type' => ['string', 'null']],
                    'timezone' => ['type' => ['string', 'null']],
                    'is_active' => ['type' => 'boolean'],
                ],
            ],
            'MobileStaffLoginRequest' => [
                'type' => 'object',
                'required' => ['email', 'password'],
                'properties' => [
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string'],
                    'device_name' => ['type' => ['string', 'null']],
                    'platform' => ['type' => ['string', 'null'], 'enum' => ['android', 'ios', null]],
                ],
            ],
            'MobileStaffLoginResponse' => [
                'type' => 'object',
                'properties' => [
                    'actor' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['staff']],
                            'user' => ['$ref' => '#/components/schemas/MobileStaffUser'],
                        ],
                    ],
                    'accounts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'account' => ['$ref' => '#/components/schemas/MobileAccount'],
                                'role' => ['type' => 'string'],
                                'token' => ['type' => 'string', 'example' => 'ladna_mobile_...'],
                                'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                            ],
                        ],
                    ],
                ],
            ],
            'MobileCustomerEmailLoginRequest' => [
                'type' => 'object',
                'required' => ['account_slug', 'email', 'password'],
                'properties' => [
                    'account_slug' => ['type' => 'string', 'example' => 'ladna-demo'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'password' => ['type' => 'string', 'minLength' => 6],
                    'device_name' => ['type' => ['string', 'null']],
                    'platform' => ['type' => ['string', 'null'], 'enum' => ['android', 'ios', null]],
                ],
            ],
            'MobileCustomerOtpSendRequest' => [
                'type' => 'object',
                'required' => ['account_slug', 'phone'],
                'properties' => [
                    'account_slug' => ['type' => 'string', 'example' => 'ladna-demo'],
                    'phone' => ['type' => 'string', 'example' => '+380671112233'],
                    'turnstile_token' => ['type' => ['string', 'null']],
                ],
            ],
            'MobileCustomerOtpSendResponse' => [
                'type' => 'object',
                'properties' => [
                    'phone' => ['type' => ['string', 'null']],
                    'resend_seconds' => ['type' => ['integer', 'null']],
                ],
            ],
            'MobileCustomerOtpVerifyRequest' => [
                'type' => 'object',
                'required' => ['account_slug', 'phone', 'code'],
                'properties' => [
                    'account_slug' => ['type' => 'string', 'example' => 'ladna-demo'],
                    'phone' => ['type' => 'string', 'example' => '+380671112233'],
                    'code' => ['type' => 'string'],
                    'device_name' => ['type' => ['string', 'null']],
                    'platform' => ['type' => ['string', 'null'], 'enum' => ['android', 'ios', null]],
                ],
            ],
            'MobileGoogleExchangeRequest' => [
                'type' => 'object',
                'required' => ['code'],
                'properties' => [
                    'code' => ['type' => 'string'],
                    'device_name' => ['type' => ['string', 'null']],
                    'platform' => ['type' => ['string', 'null'], 'enum' => ['android', 'ios', null]],
                ],
            ],
            'MobileCustomerSessionResponse' => [
                'type' => 'object',
                'properties' => [
                    'account' => ['$ref' => '#/components/schemas/MobileAccount'],
                    'actor' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['customer']],
                            'customer' => ['$ref' => '#/components/schemas/MobileCustomer'],
                        ],
                    ],
                    'token' => ['type' => 'string', 'example' => 'ladna_mobile_...'],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'MobileProfile' => [
                'type' => 'object',
                'properties' => [
                    'session' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'guard' => ['type' => 'string', 'enum' => ['staff', 'customer']],
                            'role' => ['type' => ['string', 'null']],
                            'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'account' => ['$ref' => '#/components/schemas/MobileAccount'],
                    'actor' => [
                        'type' => 'object',
                        'description' => 'Staff actors contain user, optional linked trainer, and studio permission values. Customer actors contain customer profile and customer permissions.',
                    ],
                ],
            ],
            'MobileStaffUser' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'phone' => ['type' => ['string', 'null']],
                    'avatar_url' => ['type' => ['string', 'null']],
                ],
            ],
            'MobileCustomer' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => ['string', 'null']],
                    'email' => ['type' => ['string', 'null']],
                    'phone' => ['type' => ['string', 'null']],
                    'default_language' => ['type' => ['string', 'null']],
                    'profile_complete' => ['type' => 'boolean'],
                    'email_verified' => ['type' => 'boolean'],
                    'phone_verified' => ['type' => 'boolean'],
                ],
            ],
            'MobileCustomerProfileUpdateRequest' => [
                'type' => 'object',
                'required' => ['name', 'phone'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                    'email' => ['type' => ['string', 'null'], 'format' => 'email'],
                    'password' => ['type' => ['string', 'null'], 'minLength' => 6],
                ],
            ],
            'MobileCustomerProfilePhoneOtpSendRequest' => [
                'type' => 'object',
                'required' => ['phone'],
                'properties' => [
                    'phone' => ['type' => 'string', 'example' => '+380671112233'],
                ],
            ],
            'MobileCustomerProfilePhoneOtpVerifyRequest' => [
                'type' => 'object',
                'required' => ['phone', 'code', 'name'],
                'properties' => [
                    'phone' => ['type' => 'string', 'example' => '+380671112233'],
                    'code' => ['type' => 'string', 'example' => '123456'],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => ['string', 'null'], 'format' => 'email'],
                    'password' => ['type' => ['string', 'null'], 'minLength' => 6],
                ],
            ],
            'MobileScheduledClass' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => ['string', 'null']],
                    'starts_at' => ['type' => 'string', 'format' => 'date-time'],
                    'ends_at' => ['type' => 'string', 'format' => 'date-time'],
                    'timezone' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'schedule_kind' => ['type' => ['string', 'null'], 'enum' => ['group_class', 'private_lesson', 'room_rental', 'internal_class', null]],
                    'location' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'room' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'class_type' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'activity_direction' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'trainer' => ['$ref' => '#/components/schemas/Trainer'],
                    'additional_trainers' => [
                        'type' => 'array',
                        'description' => 'Additional trainers assigned to the class. Internal classes may have multiple.',
                        'items' => ['$ref' => '#/components/schemas/Trainer'],
                    ],
                    'capacity' => ['type' => 'integer'],
                    'booked_count' => ['type' => 'integer'],
                    'available_spots' => ['type' => ['integer', 'null']],
                    'booking_open' => ['type' => 'boolean'],
                    'customer_booking' => [
                        'type' => ['object', 'null'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'status' => ['type' => 'string'],
                        ],
                    ],
                    'bookings' => [
                        'type' => 'array',
                        'description' => 'Present for staff sessions only.',
                        'items' => ['$ref' => '#/components/schemas/MobileClassBooking'],
                    ],
                ],
            ],
            'MobileCustomerBookingRequest' => [
                'type' => 'object',
                'properties' => [
                    'notes' => ['type' => ['string', 'null']],
                ],
            ],
            'MobileStaffBookingRequest' => [
                'type' => 'object',
                'required' => ['customer_id'],
                'properties' => [
                    'customer_id' => ['type' => 'integer'],
                    'notes' => ['type' => ['string', 'null']],
                ],
            ],
            'MobileBookingStatusRequest' => [
                'type' => 'object',
                'required' => ['status'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['booked', 'attended', 'no_show', 'cancelled']],
                    'notes' => ['type' => ['string', 'null']],
                ],
            ],
            'MobileClassBooking' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['booked', 'attended', 'no_show', 'cancelled']],
                    'attended_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'notes' => ['type' => ['string', 'null']],
                    'customer' => ['$ref' => '#/components/schemas/MobileCustomer'],
                    'scheduled_class' => ['$ref' => '#/components/schemas/MobileScheduledClass'],
                    'class_pass' => [
                        'type' => ['object', 'null'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'code' => ['type' => 'string'],
                            'plan_name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'MobileCustomerClassPass' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'code' => ['type' => 'string'],
                    'plan_name' => ['type' => 'string'],
                    'plan_slug' => ['type' => ['string', 'null']],
                    'status' => ['type' => 'string'],
                    'payment_status' => ['type' => 'string'],
                    'sessions_count' => ['type' => 'integer'],
                    'used_sessions_count' => ['type' => 'integer'],
                    'reserved_sessions_count' => ['type' => 'integer'],
                    'remaining_sessions_count' => ['type' => 'integer'],
                    'price_cents' => ['type' => 'integer'],
                    'paid_amount_cents' => ['type' => 'integer'],
                    'remaining_payment_cents' => ['type' => 'integer'],
                    'currency' => ['type' => 'string'],
                    'purchased_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'opened_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'usable_until_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'is_active' => ['type' => 'boolean'],
                ],
            ],
            'MobileDeviceTokenRequest' => [
                'type' => 'object',
                'required' => ['platform', 'token'],
                'properties' => [
                    'provider' => ['type' => ['string', 'null'], 'enum' => ['fcm', null]],
                    'platform' => ['type' => 'string', 'enum' => ['android', 'ios']],
                    'token' => ['type' => 'string'],
                    'device_name' => ['type' => ['string', 'null']],
                    'app_version' => ['type' => ['string', 'null']],
                ],
            ],
            'MobileDeviceTokenResponse' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'provider' => ['type' => 'string'],
                    'platform' => ['type' => 'string'],
                    'last_seen_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                ],
            ],
            'ScheduledClass' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => ['string', 'null']],
                    'color' => ['type' => 'string', 'pattern' => '^#[0-9A-F]{6}$', 'description' => 'Resolved class accent color for schedule rendering.'],
                    'text_color' => ['type' => 'string', 'pattern' => '^#[0-9A-F]{6}$', 'description' => 'Readable text color for the resolved class accent color.'],
                    'starts_at' => ['type' => 'string', 'format' => 'date-time'],
                    'ends_at' => ['type' => 'string', 'format' => 'date-time'],
                    'location' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'room' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'class_type' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'activity_direction' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'schedule_kind' => ['type' => 'string', 'enum' => ['group_class']],
                    'trainer' => ['$ref' => '#/components/schemas/Trainer'],
                    'capacity' => ['type' => ['integer', 'null']],
                    'available_spots' => ['type' => ['integer', 'null']],
                    'booking_cutoff_minutes' => ['type' => ['integer', 'null']],
                    'status' => ['type' => 'string', 'example' => 'scheduled'],
                ],
            ],
            'PublicScheduleDay' => [
                'type' => 'object',
                'required' => ['date', 'iso_weekday', 'classes'],
                'properties' => [
                    'date' => ['type' => 'string', 'format' => 'date'],
                    'iso_weekday' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                    'classes' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ScheduledClass'],
                    ],
                ],
            ],
            'PublicScheduleWeekMeta' => [
                'type' => 'object',
                'required' => ['timezone', 'week_start', 'week_end'],
                'properties' => [
                    'timezone' => ['type' => 'string', 'example' => 'Europe/Kyiv'],
                    'week_start' => ['type' => 'string', 'format' => 'date'],
                    'week_end' => ['type' => 'string', 'format' => 'date'],
                ],
            ],
            'PriceGroup' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'enum' => ['group_class', 'private_lesson', 'room_rental']],
                    'schedule_kind' => ['type' => 'string', 'enum' => ['group_class', 'private_lesson', 'room_rental']],
                    'title' => ['type' => 'string'],
                    'sections' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/PriceSection'],
                    ],
                ],
            ],
            'PriceSection' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'Section key. Segment sections use segment:{slug}; anonymous unsegmented sections use an internal key.'],
                    'title' => ['type' => 'string', 'description' => 'Visible segment title, or an empty string for anonymous unsegmented plan sections.'],
                    'plans' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/ClassPassPlan'],
                    ],
                ],
            ],
            'ClassPassPlan' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'schedule_kind' => ['type' => 'string'],
                    'segment' => [
                        'anyOf' => [
                            ['$ref' => '#/components/schemas/ClassPassSegment'],
                            ['type' => 'null'],
                        ],
                    ],
                    'description' => ['type' => ['string', 'null']],
                    'price_cents' => ['type' => 'integer'],
                    'currency' => ['type' => 'string'],
                    'checkout_url' => [
                        'type' => 'string',
                        'format' => 'uri',
                        'description' => 'Absolute Ladna URL for the preselected external class-pass checkout.',
                    ],
                    'sessions_count' => ['type' => 'integer'],
                    'validity_days' => ['type' => 'integer'],
                    'total_validity_days' => ['type' => 'integer'],
                    'available_from_time' => ['type' => ['string', 'null'], 'example' => '09:00'],
                    'available_until_time' => ['type' => ['string', 'null'], 'example' => '17:00'],
                    'allows_any_time' => ['type' => 'boolean'],
                    'any_time_addon_price_cents' => ['type' => ['integer', 'null']],
                    'is_trial' => ['type' => 'boolean'],
                    'class_types' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NamedEntity']],
                    'trainer_types' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NamedEntity']],
                    'rooms' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NamedEntity']],
                ],
            ],
            'ClassPassSegment' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'schedule_kind' => ['type' => 'string'],
                ],
            ],
            'WebsiteLeadRequest' => [
                'type' => 'object',
                'required' => ['phone'],
                'properties' => [
                    'phone' => ['type' => 'string', 'example' => '+380671112233'],
                    'name' => ['type' => ['string', 'null'], 'example' => 'Олена Коваль'],
                    'source_page' => ['type' => ['string', 'null'], 'example' => 'https://studio.example.com/trial'],
                ],
            ],
            'FestivalBattleAudienceScoreRequest' => [
                'type' => 'object',
                'required' => ['audience_score_a', 'audience_score_b'],
                'properties' => [
                    'audience_score_a' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000, 'example' => 612345],
                    'audience_score_b' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000, 'example' => 387655],
                    'measurement' => [
                        'type' => 'object',
                        'required' => ['metric', 'baseline_duration_ms', 'duration_ms'],
                        'additionalProperties' => false,
                        'properties' => [
                            'metric' => ['type' => 'string', 'enum' => ['baseline_adjusted_integrated_energy']],
                            'baseline_duration_ms' => ['type' => 'integer', 'enum' => [2000]],
                            'duration_ms' => ['type' => 'integer', 'enum' => [5000]],
                            'baseline_dbfs' => ['type' => 'number', 'minimum' => -160, 'maximum' => 0],
                            'mean_dbfs_a' => ['type' => 'number', 'minimum' => -160, 'maximum' => 0],
                            'mean_dbfs_b' => ['type' => 'number', 'minimum' => -160, 'maximum' => 0],
                            'peak_dbfs_a' => ['type' => 'number', 'minimum' => -160, 'maximum' => 0],
                            'peak_dbfs_b' => ['type' => 'number', 'minimum' => -160, 'maximum' => 0],
                        ],
                    ],
                ],
                'description' => 'The two scores must total exactly 1,000,000. Measurement metadata is optional and never contains audio.',
            ],
            'FestivalBattlePerformer' => [
                'type' => 'object',
                'required' => ['id', 'name'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
            ],
            'FestivalBattleMatch' => [
                'type' => 'object',
                'required' => ['id', 'edition_label', 'category_label', 'round_number', 'position', 'state', 'performer_a', 'performer_b', 'judge_votes', 'audience', 'combined'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'edition_label' => ['type' => 'string'],
                    'category_label' => ['type' => 'string'],
                    'round_number' => ['type' => 'integer'],
                    'position' => ['type' => 'integer'],
                    'state' => ['type' => 'string', 'enum' => ['ready', 'waiting_for_judges', 'jury_decision_required', 'completed']],
                    'performer_a' => ['$ref' => '#/components/schemas/FestivalBattlePerformer'],
                    'performer_b' => ['$ref' => '#/components/schemas/FestivalBattlePerformer'],
                    'judge_votes' => [
                        'type' => 'object',
                        'properties' => [
                            'required' => ['type' => 'integer', 'enum' => [4]],
                            'submitted' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4],
                            'a' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4],
                            'b' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4],
                        ],
                    ],
                    'audience' => [
                        'type' => 'object',
                        'properties' => [
                            'score_a' => ['type' => ['integer', 'null']],
                            'score_b' => ['type' => ['integer', 'null']],
                            'percentage_a' => ['type' => ['number', 'null']],
                            'percentage_b' => ['type' => ['number', 'null']],
                        ],
                    ],
                    'combined' => [
                        'type' => 'object',
                        'properties' => [
                            'percentage_a' => ['type' => ['number', 'null']],
                            'percentage_b' => ['type' => ['number', 'null']],
                        ],
                    ],
                    'winner' => [
                        'anyOf' => [
                            ['$ref' => '#/components/schemas/FestivalBattlePerformer'],
                            ['type' => 'null'],
                        ],
                    ],
                ],
            ],
            'FestivalBattleMeta' => [
                'type' => 'object',
                'properties' => [
                    'locale' => ['type' => 'string', 'enum' => ['en', 'uk']],
                    'poll_interval_seconds' => ['type' => 'integer', 'enum' => [5]],
                ],
            ],
            'FestivalBattleConflictResponse' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'code' => ['type' => 'string', 'enum' => ['audience_score_conflict']],
                    'data' => ['$ref' => '#/components/schemas/FestivalBattleMatch'],
                ],
            ],
            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'code' => ['type' => ['string', 'null']],
                ],
            ],
            'WebsiteLead' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/WebsiteLeadRequest'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'status' => ['type' => 'string', 'enum' => ['new', 'rejected', 'booked', 'callback']],
                            'created_at' => [
                                'type' => 'string',
                                'format' => 'date-time',
                                'description' => 'Lead creation time in the bearer token account timezone.',
                                'example' => '2026-06-24T11:48:00+03:00',
                            ],
                        ],
                    ],
                ],
            ],
            'McpToolCallRequest' => [
                'type' => 'object',
                'required' => ['jsonrpc', 'id', 'method', 'params'],
                'properties' => [
                    'jsonrpc' => ['type' => 'string', 'enum' => ['2.0']],
                    'id' => ['type' => ['integer', 'string'], 'example' => 1],
                    'method' => ['type' => 'string', 'enum' => ['tools/call']],
                    'params' => [
                        'type' => 'object',
                        'required' => ['name', 'arguments'],
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'enum' => [
                                    'describe-ladna-skills',
                                    'get-studio-profile',
                                    'get-class-counts-for-day',
                                    'get-class-bookings-for-day',
                                    'search-customers',
                                    'investigate-customer-booking-ledger',
                                    'search-owner-help',
                                    'get-owner-help-page',
                                    'get-business-logic-reference',
                                    'get-payment-overview',
                                    'search-payments',
                                    'get-financial-report',
                                    'get-cashbox-overview',
                                    'get-earnings-report',
                                    'get-rental-report',
                                    'get-payroll-overview',
                                    'get-events-overview',
                                    'get-event-summary',
                                ],
                            ],
                            'arguments' => [
                                'type' => 'object',
                                'description' => 'Tool-specific arguments. Do not pass account_id, studio_id, tenant_id, user_id, or trainer_id for scoping.',
                            ],
                        ],
                    ],
                ],
            ],
            'McpToolCallResponse' => [
                'type' => 'object',
                'properties' => [
                    'jsonrpc' => ['type' => 'string', 'example' => '2.0'],
                    'id' => ['type' => ['integer', 'string'], 'example' => 1],
                    'result' => [
                        'type' => 'object',
                        'properties' => [
                            'structuredContent' => [
                                'type' => 'object',
                                'description' => 'Machine-readable tool output for successful calls.',
                                'anyOf' => [
                                    ['$ref' => '#/components/schemas/McpOwnerHelpSearchResponse'],
                                    ['$ref' => '#/components/schemas/McpLadnaSkillsResponse'],
                                    ['$ref' => '#/components/schemas/McpCustomerBookingInvestigationResponse'],
                                    ['type' => 'object'],
                                ],
                            ],
                            'isError' => ['type' => 'boolean'],
                            'content' => ['type' => 'array'],
                        ],
                    ],
                ],
            ],
            'McpOwnerHelpSearchResponse' => [
                'type' => 'object',
                'required' => ['query', 'results'],
                'properties' => [
                    'query' => ['type' => 'string', 'example' => 'як додати клієнта'],
                    'results' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/McpOwnerHelpSearchResult'],
                    ],
                ],
            ],
            'McpCustomerBookingInvestigationArguments' => [
                'type' => 'object',
                'required' => ['customer_id'],
                'additionalProperties' => false,
                'description' => 'Arguments for investigate-customer-booking-ledger. The date window affects only the detailed timeline; all-time history, ordinary trial eligibility, and manual-override qualification use as_of.',
                'properties' => [
                    'customer_id' => ['type' => 'integer', 'minimum' => 1, 'example' => 63],
                    'from_date' => ['type' => ['string', 'null'], 'format' => 'date', 'example' => '2026-07-01'],
                    'to_date' => ['type' => ['string', 'null'], 'format' => 'date', 'example' => '2026-07-31'],
                    'as_of' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'RFC3339 timestamp at or before now, evaluated in the bearer account timezone. Defaults to now.',
                        'example' => '2026-07-28T20:05:00+03:00',
                    ],
                    'source' => [
                        'type' => 'string',
                        'enum' => ['manual', 'online_payment'],
                        'default' => 'manual',
                    ],
                ],
            ],
            'McpCustomerBookingInvestigationResponse' => [
                'type' => 'object',
                'required' => ['status'],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['found', 'not_found']],
                    'customer' => [
                        'type' => 'object',
                        'properties' => [
                            'customer_id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                    'period' => [
                        'type' => 'object',
                        'description' => 'Bounded detailed-timeline window only.',
                        'properties' => [
                            'from_date' => ['type' => 'string', 'format' => 'date'],
                            'to_date' => ['type' => 'string', 'format' => 'date'],
                            'timezone' => ['type' => 'string'],
                        ],
                    ],
                    'customer_history_summary' => ['$ref' => '#/components/schemas/McpCustomerHistorySummary'],
                    'trial_eligibility' => ['$ref' => '#/components/schemas/McpTrialEligibility'],
                    'manual_override' => ['$ref' => '#/components/schemas/McpManualTrialOverride'],
                    'summary' => ['type' => 'object'],
                    'passes' => [
                        'type' => 'array',
                        'description' => 'Class-pass money remains in integer cents in external MCP output.',
                        'items' => ['type' => 'object'],
                    ],
                    'bookings' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'timeline' => ['type' => 'array', 'items' => ['type' => 'object']],
                ],
                'example' => [
                    'status' => 'found',
                    'customer' => ['customer_id' => 63, 'name' => 'Анна Д.'],
                    'period' => [
                        'from_date' => '2026-07-01',
                        'to_date' => '2026-07-31',
                        'timezone' => 'Europe/Kyiv',
                    ],
                    'customer_history_summary' => [
                        'evaluated_as_of' => '2026-07-28T20:05:00+03:00',
                        'timezone' => 'Europe/Kyiv',
                        'counted_bookings_count' => 5,
                        'prior_attended_bookings_count' => 2,
                        'earliest_prior_attended_booking' => [
                            'booking_id' => 501,
                            'status' => 'attended',
                            'attended_at' => '2026-07-23T20:02:00+03:00',
                        ],
                        'supporting_bookings' => [
                            'returned' => 5,
                            'total' => 5,
                            'limit' => 20,
                            'truncated' => false,
                            'items' => [],
                        ],
                    ],
                    'trial_eligibility' => [
                        'status' => 'ineligible',
                        'evaluated_as_of' => '2026-07-28T20:05:00+03:00',
                        'source' => 'manual',
                        'reason_codes' => ['multiple_existing_bookings'],
                        'counted_bookings_count' => 5,
                        'active_reservations_count' => 0,
                        'rule_reference_key' => 'trial_class_pass_eligibility',
                        'evidence_complete' => true,
                        'supporting_bookings' => [
                            'returned' => 5,
                            'total' => 5,
                            'limit' => 20,
                            'truncated' => false,
                            'items' => [],
                        ],
                        'trial_plans' => [
                            'returned' => 1,
                            'total' => 1,
                            'limit' => 20,
                            'truncated' => false,
                            'items' => [[
                                'class_pass_plan_id' => 14,
                                'name' => 'Пробне заняття',
                                'is_active' => true,
                                'price_cents' => 25000,
                                'currency' => 'UAH',
                                'current_configuration' => true,
                            ]],
                        ],
                    ],
                    'manual_override' => [
                        'status' => 'actor_permissions_not_evaluated',
                        'available' => false,
                        'customer_qualifies' => true,
                        'reason_codes' => ['actor_permissions_not_evaluated'],
                        'evaluated_as_of' => '2026-07-28T20:05:00+03:00',
                        'source' => 'manual',
                        'timezone' => 'Europe/Kyiv',
                        'normal_eligibility_status' => 'ineligible',
                        'class_pass_history_count' => 0,
                        'successful_payments_count' => 0,
                        'actor_permissions_evaluated' => false,
                        'actor_has_required_permissions' => null,
                        'required_permissions' => [
                            'issue_customer_class_passes',
                            'manage_customer_class_passes',
                        ],
                        'requires_comment' => true,
                        'human_exception' => true,
                    ],
                    'passes' => [[
                        'customer_class_pass_id' => 88,
                        'plan_name' => 'Разове відвідування',
                        'price_cents' => 45000,
                        'paid_amount_cents' => 25000,
                        'remaining_payment_cents' => 20000,
                        'currency' => 'UAH',
                    ]],
                ],
            ],
            'McpCustomerHistorySummary' => [
                'type' => 'object',
                'required' => [
                    'evaluated_as_of',
                    'timezone',
                    'counted_bookings_count',
                    'prior_attended_bookings_count',
                    'supporting_bookings',
                ],
                'properties' => [
                    'evaluated_as_of' => ['type' => 'string', 'format' => 'date-time'],
                    'timezone' => ['type' => 'string'],
                    'counted_bookings_count' => [
                        'type' => 'integer',
                        'description' => 'Exact all-time count of bookings created by as_of and not correction-removed by as_of. All booking statuses count.',
                    ],
                    'prior_attended_bookings_count' => ['type' => 'integer'],
                    'attendance_evidence_basis' => ['type' => 'string'],
                    'attendance_evidence_complete' => ['type' => 'boolean'],
                    'earliest_prior_attended_booking' => [
                        'anyOf' => [
                            ['type' => 'object'],
                            ['type' => 'null'],
                        ],
                    ],
                    'supporting_bookings' => ['$ref' => '#/components/schemas/McpBoundedBookingEvidence'],
                ],
            ],
            'McpBoundedBookingEvidence' => [
                'type' => 'object',
                'required' => ['returned', 'total', 'limit', 'truncated', 'items'],
                'properties' => [
                    'returned' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                    'limit' => ['type' => 'integer', 'enum' => [20]],
                    'truncated' => ['type' => 'boolean'],
                    'items' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object']],
                ],
            ],
            'McpTrialEligibility' => [
                'type' => 'object',
                'required' => [
                    'status',
                    'evaluated_as_of',
                    'source',
                    'timezone',
                    'reason_codes',
                    'counted_bookings_count',
                    'active_reservations_count',
                    'rule_reference_key',
                    'evidence_complete',
                    'supporting_bookings',
                    'trial_plans',
                ],
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['eligible', 'ineligible', 'not_configured']],
                    'evaluated_as_of' => ['type' => 'string', 'format' => 'date-time'],
                    'source' => ['type' => 'string', 'enum' => ['manual', 'online_payment']],
                    'timezone' => ['type' => 'string'],
                    'reason_codes' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => [
                                'no_trial_plan_configured',
                                'no_existing_bookings',
                                'existing_booking_non_manual',
                                'multiple_existing_bookings',
                                'active_reservation_exists',
                                'single_unreserved_booking_manual_exception',
                            ],
                        ],
                    ],
                    'counted_bookings_count' => ['type' => 'integer'],
                    'active_reservations_count' => ['type' => 'integer'],
                    'rule_reference_key' => ['type' => 'string', 'enum' => ['trial_class_pass_eligibility']],
                    'evidence_complete' => ['type' => 'boolean'],
                    'historical_reconstruction' => ['type' => 'object'],
                    'supporting_bookings' => ['$ref' => '#/components/schemas/McpBoundedBookingEvidence'],
                    'trial_plans' => [
                        'type' => 'object',
                        'required' => ['returned', 'total', 'limit', 'truncated', 'items'],
                        'properties' => [
                            'returned' => ['type' => 'integer'],
                            'total' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer', 'enum' => [20]],
                            'truncated' => ['type' => 'boolean'],
                            'items' => [
                                'type' => 'array',
                                'maxItems' => 20,
                                'items' => ['$ref' => '#/components/schemas/McpTrialPlanCandidate'],
                            ],
                        ],
                    ],
                ],
            ],
            'McpManualTrialOverride' => [
                'type' => 'object',
                'description' => 'Qualification for the audited human exception. Ordinary trial_eligibility remains unchanged and authoritative. External read-only MCP tokens cannot prove a dashboard actor permission, so available remains false and status is actor_permissions_not_evaluated when only the customer history qualifies.',
                'required' => [
                    'status',
                    'available',
                    'customer_qualifies',
                    'reason_codes',
                    'evaluated_as_of',
                    'source',
                    'timezone',
                    'normal_eligibility_status',
                    'class_pass_history_count',
                    'successful_payments_count',
                    'actor_permissions_evaluated',
                    'actor_has_required_permissions',
                    'required_permissions',
                    'requires_comment',
                    'human_exception',
                ],
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['available', 'unavailable', 'actor_permissions_not_evaluated'],
                    ],
                    'available' => ['type' => 'boolean'],
                    'customer_qualifies' => ['type' => 'boolean'],
                    'reason_codes' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => [
                                'manual_override_available',
                                'manual_source_required',
                                'normal_trial_eligibility_available',
                                'existing_class_pass_history',
                                'successful_payment_history',
                                'actor_permissions_not_evaluated',
                                'missing_required_permissions',
                                'no_trial_plan_configured',
                            ],
                        ],
                    ],
                    'evaluated_as_of' => ['type' => 'string', 'format' => 'date-time'],
                    'source' => ['type' => 'string', 'enum' => ['manual', 'online_payment']],
                    'timezone' => ['type' => 'string'],
                    'normal_eligibility_status' => ['type' => 'string', 'enum' => ['eligible', 'ineligible']],
                    'class_pass_history_count' => ['type' => 'integer', 'minimum' => 0],
                    'successful_payments_count' => ['type' => 'integer', 'minimum' => 0],
                    'actor_permissions_evaluated' => ['type' => 'boolean'],
                    'actor_has_required_permissions' => ['type' => ['boolean', 'null']],
                    'required_permissions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'requires_comment' => ['type' => 'boolean', 'enum' => [true]],
                    'human_exception' => ['type' => 'boolean', 'enum' => [true]],
                ],
            ],
            'McpTrialPlanCandidate' => [
                'type' => 'object',
                'required' => ['class_pass_plan_id', 'name', 'is_active', 'price_cents', 'currency', 'current_configuration'],
                'properties' => [
                    'class_pass_plan_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'is_active' => ['type' => 'boolean'],
                    'price_cents' => [
                        'type' => 'integer',
                        'description' => 'Current trial-plan price in raw minor currency units.',
                    ],
                    'currency' => ['type' => 'string'],
                    'sessions_count' => ['type' => 'integer'],
                    'current_configuration' => ['type' => 'boolean', 'enum' => [true]],
                ],
            ],
            'McpLadnaSkillsResponse' => [
                'type' => 'object',
                'required' => ['assistant', 'read_capabilities', 'guided_dialogs', 'mutating_actions', 'limits'],
                'properties' => [
                    'assistant' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'example' => 'Ladna'],
                            'purpose' => ['type' => 'string'],
                            'scope' => ['type' => 'string'],
                            'current_channel' => ['type' => ['string', 'null'], 'example' => 'dashboard_chat'],
                        ],
                    ],
                    'read_capabilities' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/McpLadnaCapability'],
                    ],
                    'guided_dialogs' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/McpLadnaActionCapability'],
                    ],
                    'mutating_actions' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/McpLadnaActionCapability'],
                    ],
                    'limits' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'studio_scope' => [
                        'type' => 'object',
                        'description' => 'Studio scope resolved from the bearer account API token.',
                    ],
                ],
            ],
            'McpLadnaCapability' => [
                'type' => 'object',
                'required' => ['key', 'title', 'description', 'tools'],
                'properties' => [
                    'key' => ['type' => 'string', 'example' => 'class_booking_details'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'tools' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'required_ability' => ['type' => ['string', 'null'], 'example' => 'mcp:customers:read'],
                    'required_abilities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'example' => ['mcp:customers:read', 'mcp:class-passes:read'],
                    ],
                    'required_user_permission' => ['type' => ['string', 'null'], 'example' => 'manage_customer_class_passes'],
                ],
            ],
            'McpLadnaActionCapability' => [
                'type' => 'object',
                'required' => ['key', 'title', 'description', 'confirmation_required'],
                'properties' => [
                    'key' => ['type' => 'string', 'example' => 'create-booking'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'confirmation_required' => ['type' => 'boolean'],
                    'required_user_permission' => ['type' => ['string', 'null'], 'example' => 'manageBookings'],
                ],
            ],
            'McpOwnerHelpSearchResult' => [
                'type' => 'object',
                'required' => ['slug', 'title', 'summary', 'score', 'matched_sections', 'fragments'],
                'properties' => [
                    'slug' => ['type' => 'string', 'example' => 'customers-bookings'],
                    'title' => ['type' => 'string', 'example' => 'Клієнти, записи та відвідування'],
                    'summary' => ['type' => 'string'],
                    'score' => ['type' => 'integer', 'description' => 'Deterministic relevance score for this query.'],
                    'matched_sections' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'fragments' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/McpOwnerHelpFragment'],
                    ],
                ],
            ],
            'McpOwnerHelpFragment' => [
                'type' => 'object',
                'required' => ['section_title', 'excerpt', 'steps', 'score'],
                'properties' => [
                    'section_title' => ['type' => 'string', 'example' => 'Як додати клієнта вручну'],
                    'excerpt' => ['type' => 'string', 'description' => 'Short curated help excerpt.'],
                    'steps' => [
                        'type' => 'array',
                        'maxItems' => 6,
                        'items' => ['type' => 'string'],
                    ],
                    'score' => ['type' => 'integer'],
                ],
            ],
            'NamedEntity' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                ],
            ],
            'Trainer' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'photo_url' => ['type' => ['string', 'null']],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<int, array{lang: string, label: string, source: string}>
     */
    private function codeSamples(string $method, string $path, ?array $body = null, bool $authenticated = false): array
    {
        $url = rtrim(url('/'), '/').$path;
        $json = $body ? json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $jsonForPhp = $json ? str_replace("'", "\\'", $json) : null;

        return [
            [
                'lang' => 'PHP',
                'label' => 'PHP',
                'source' => $body
                    ? "\$response = (new \\GuzzleHttp\\Client())->request('{$method}', '{$url}', [\n    'headers' => [\n        'Authorization' => 'Bearer ladna_your_token',\n        'Accept' => 'application/json',\n    ],\n    'json' => json_decode('{$jsonForPhp}', true),\n]);\n\n\$data = json_decode((string) \$response->getBody(), true);"
                    : ($authenticated
                        ? "\$response = (new \\GuzzleHttp\\Client())->request('{$method}', '{$url}', [\n    'headers' => [\n        'Authorization' => 'Bearer ladna_your_token',\n        'Accept' => 'application/json',\n    ],\n]);\n\n\$data = json_decode((string) \$response->getBody(), true);"
                        : "\$response = (new \\GuzzleHttp\\Client())->request('{$method}', '{$url}', [\n    'headers' => ['Accept' => 'application/json'],\n]);\n\n\$data = json_decode((string) \$response->getBody(), true);"),
            ],
            [
                'lang' => 'Python',
                'label' => 'Python',
                'source' => $body
                    ? "import requests\n\nresponse = requests.request(\n    '{$method}',\n    '{$url}',\n    headers={\n        'Authorization': 'Bearer ladna_your_token',\n        'Accept': 'application/json',\n    },\n    json={$json},\n)\nresponse.raise_for_status()\ndata = response.json()"
                    : ($authenticated
                        ? "import requests\n\nresponse = requests.request(\n    '{$method}',\n    '{$url}',\n    headers={\n        'Authorization': 'Bearer ladna_your_token',\n        'Accept': 'application/json',\n    },\n)\nresponse.raise_for_status()\ndata = response.json()"
                        : "import requests\n\nresponse = requests.request(\n    '{$method}',\n    '{$url}',\n    headers={'Accept': 'application/json'},\n)\nresponse.raise_for_status()\ndata = response.json()"),
            ],
            [
                'lang' => 'JavaScript',
                'label' => 'JS',
                'source' => $body
                    ? "const response = await fetch('{$url}', {\n  method: '{$method}',\n  headers: {\n    'Authorization': 'Bearer ladna_your_token',\n    'Accept': 'application/json',\n    'Content-Type': 'application/json',\n  },\n  body: JSON.stringify({$json}),\n});\n\nconst data = await response.json();"
                    : ($authenticated
                        ? "const response = await fetch('{$url}', {\n  method: '{$method}',\n  headers: {\n    'Authorization': 'Bearer ladna_your_token',\n    'Accept': 'application/json',\n  },\n});\n\nconst data = await response.json();"
                        : "const response = await fetch('{$url}', {\n  method: '{$method}',\n  headers: { 'Accept': 'application/json' },\n});\n\nconst data = await response.json();"),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function mcpToolCallBody(string $toolName, array $arguments): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
        ];
    }
}
