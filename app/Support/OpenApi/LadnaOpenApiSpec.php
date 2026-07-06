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
                'description' => 'Public schedule, public prices, native mobile app, website lead intake, and account-scoped MCP tools for Ladna studios.',
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
                ['name' => 'MCP'],
            ],
            'paths' => [
                '/api/v1/public/{accountSlug}/{locationSlug}/schedule' => $this->publicSchedulePath('Returns upcoming public group classes for a studio location.'),
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
                '/api/v1/mobile/staff/customers' => $this->mobileStaffCustomersPath(),
                '/api/v1/website-leads' => $this->websiteLeadPath(),
                '/mcp/ladna-studio' => $this->mcpStudioPath(),
            ],
            'components' => [
                'securitySchemes' => [
                    'AccountBearerToken' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Ladna account API token',
                        'description' => 'Bearer token issued in studio settings. Website lead intake requires website_leads:create. MCP tools require their documented mcp:* abilities and always resolve account scope from this token.',
                    ],
                    'MobileBearerToken' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Ladna native mobile session token',
                        'description' => 'Bearer token returned by mobile staff or customer authentication endpoints. It is scoped to one studio account and expires automatically.',
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
                'samples' => $this->codeSamples('GET', '/api/v1/public/charmpole/main-studio/schedule'),
            ],
            'public_price' => [
                'title' => __('app.api_docs_example_public_price'),
                'method' => 'GET',
                'path' => '/api/v1/public/{accountSlug}/{locationSlug}/price',
                'samples' => $this->codeSamples('GET', '/api/v1/public/charmpole/main-studio/price'),
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
            'mcp_class_bookings' => [
                'title' => __('app.api_docs_example_mcp_class_bookings'),
                'method' => 'POST',
                'path' => '/mcp/ladna-studio',
                'samples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-class-bookings-for-day', [
                    'date' => '2026-06-30',
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
                'x-codeSamples' => $this->codeSamples('GET', '/api/v1/public/charmpole/main-studio/schedule'),
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
                ],
                'x-codeSamples' => $this->codeSamples('GET', '/api/v1/public/charmpole/main-studio/price'),
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
                'summary' => 'Updates booking status from a staff session with mark_attendance or manage_bookings permission.',
                'security' => $this->mobileSecurity(),
                'parameters' => [$this->classBookingParameter()],
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileBookingStatusRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Updated booking.', ['$ref' => '#/components/schemas/MobileClassBooking']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
            ],
            'delete' => [
                'tags' => ['Mobile bookings'],
                'summary' => 'Cancels a booking. Customers can cancel their own future bookings; staff need manage_bookings.',
                'security' => $this->mobileSecurity(),
                'parameters' => [$this->classBookingParameter()],
                'responses' => [
                    '200' => $this->jsonDataResponse('Cancelled booking.', ['$ref' => '#/components/schemas/MobileClassBooking']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '404' => ['$ref' => '#/components/responses/NotFound'],
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
                'summary' => 'Updates the current customer profile for the session account.',
                'security' => $this->mobileSecurity(),
                'requestBody' => $this->jsonRequestBody('#/components/schemas/MobileCustomerProfileUpdateRequest'),
                'responses' => [
                    '200' => $this->jsonDataResponse('Updated customer profile.', ['$ref' => '#/components/schemas/MobileCustomer']),
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
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
    private function mcpStudioPath(): array
    {
        return [
            'post' => [
                'tags' => ['MCP'],
                'summary' => 'Calls Ladna studio MCP tools through JSON-RPC in the bearer token account scope.',
                'description' => 'The endpoint is not public. It requires a Ladna account API bearer token. Each tool checks its own ability, such as mcp:read, mcp:customers:read, mcp:bookings:create, mcp:bookings:cancel, or mcp:logic:read. Tool calls never accept account_id or tenant_id arguments for scoping.',
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
                                'describe_ladna_skills' => [
                                    'value' => $this->mcpToolCallBody('describe-ladna-skills', [
                                        'channel' => 'dashboard_chat',
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
                    '429' => ['$ref' => '#/components/responses/TooManyRequests'],
                ],
                'x-codeSamples' => $this->codeSamples('POST', '/mcp/ladna-studio', $this->mcpToolCallBody('get-class-bookings-for-day', [
                    'date' => '2026-06-30',
                ])),
            ],
        ];
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
                'example' => 'charmpole',
            ],
            [
                'name' => 'locationSlug',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
                'example' => 'main-studio',
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
            'example' => 'charmpole',
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
            'ValidationError' => [
                'description' => 'Request validation failed.',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string'],
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
                    'account_slug' => ['type' => 'string', 'example' => 'charmpole'],
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
                    'account_slug' => ['type' => 'string', 'example' => 'charmpole'],
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
                    'account_slug' => ['type' => 'string', 'example' => 'charmpole'],
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
                    'schedule_kind' => ['type' => ['string', 'null'], 'enum' => ['group_class', 'private_lesson', 'room_rental', null]],
                    'location' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'room' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'class_type' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'activity_direction' => ['$ref' => '#/components/schemas/NamedEntity'],
                    'trainer' => ['$ref' => '#/components/schemas/Trainer'],
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
                                    'search-owner-help',
                                    'get-owner-help-page',
                                    'get-business-logic-reference',
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
    private function codeSamples(string $method, string $path, ?array $body = null): array
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
                    : "\$response = (new \\GuzzleHttp\\Client())->request('{$method}', '{$url}', [\n    'headers' => ['Accept' => 'application/json'],\n]);\n\n\$data = json_decode((string) \$response->getBody(), true);",
            ],
            [
                'lang' => 'Python',
                'label' => 'Python',
                'source' => $body
                    ? "import requests\n\nresponse = requests.request(\n    '{$method}',\n    '{$url}',\n    headers={\n        'Authorization': 'Bearer ladna_your_token',\n        'Accept': 'application/json',\n    },\n    json={$json},\n)\nresponse.raise_for_status()\ndata = response.json()"
                    : "import requests\n\nresponse = requests.request(\n    '{$method}',\n    '{$url}',\n    headers={'Accept': 'application/json'},\n)\nresponse.raise_for_status()\ndata = response.json()",
            ],
            [
                'lang' => 'JavaScript',
                'label' => 'JS',
                'source' => $body
                    ? "const response = await fetch('{$url}', {\n  method: '{$method}',\n  headers: {\n    'Authorization': 'Bearer ladna_your_token',\n    'Accept': 'application/json',\n    'Content-Type': 'application/json',\n  },\n  body: JSON.stringify({$json}),\n});\n\nconst data = await response.json();"
                    : "const response = await fetch('{$url}', {\n  method: '{$method}',\n  headers: { 'Accept': 'application/json' },\n});\n\nconst data = await response.json();",
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
