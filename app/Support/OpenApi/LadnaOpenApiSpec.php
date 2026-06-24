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
                'description' => 'Public schedule, public prices, and website lead intake API for Ladna studios.',
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
            ],
            'paths' => [
                '/api/v1/public/{accountSlug}/{locationSlug}/schedule' => $this->publicSchedulePath('Returns upcoming public group classes for a studio location.'),
                '/api/v1/public/{accountSlug}/{locationSlug}/classes' => $this->publicSchedulePath('Alias for the public schedule endpoint.'),
                '/api/v1/public/{accountSlug}/{locationSlug}/price' => $this->publicPricePath(),
                '/api/v1/website-leads' => $this->websiteLeadPath(),
            ],
            'components' => [
                'securitySchemes' => [
                    'AccountBearerToken' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Ladna account API token',
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
                'summary' => 'Creates a website lead for the studio identified by the bearer token.',
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
                    'key' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
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
                    'description' => ['type' => ['string', 'null']],
                    'price_cents' => ['type' => 'integer'],
                    'currency' => ['type' => 'string'],
                    'sessions_count' => ['type' => 'integer'],
                    'validity_days' => ['type' => 'integer'],
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
}
