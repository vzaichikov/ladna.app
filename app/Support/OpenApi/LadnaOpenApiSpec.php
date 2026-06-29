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
                'description' => 'Public schedule, public prices, website lead intake, and account-scoped MCP tools for Ladna studios.',
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
                ['name' => 'Website leads'],
                ['name' => 'MCP'],
            ],
            'paths' => [
                '/api/v1/public/{accountSlug}/{locationSlug}/schedule' => $this->publicSchedulePath('Returns upcoming public group classes for a studio location.'),
                '/api/v1/public/{accountSlug}/{locationSlug}/classes' => $this->publicSchedulePath('Alias for the public schedule endpoint.'),
                '/api/v1/public/{accountSlug}/{locationSlug}/price' => $this->publicPricePath(),
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
