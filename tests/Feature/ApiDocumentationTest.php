<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_api_documentation_page_renders_endpoints_and_code_examples(): void
    {
        $this->get(route('api-docs.show'))
            ->assertOk()
            ->assertSee(__('app.api_documentation'))
            ->assertSee('/api/v1/public/{accountSlug}/{locationSlug}/schedule')
            ->assertSee('/api/v1/public/{accountSlug}/{locationSlug}/price')
            ->assertSee('/api/v1/website-leads')
            ->assertSee('PHP')
            ->assertSee('Python')
            ->assertSee('JS')
            ->assertSee('Authorization: Bearer ladna_your_token');
    }

    public function test_openapi_json_documents_public_and_lead_endpoints(): void
    {
        $response = $this->getJson(route('api-docs.openapi'));

        $response
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/schedule.get.tags.0', 'Public schedule')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/price.get.tags.0', 'Public prices')
            ->assertJsonPath('paths./api/v1/website-leads.post.tags.0', 'Website leads')
            ->assertJsonPath('paths./api/v1/website-leads.post.security.0.AccountBearerToken', [])
            ->assertJsonPath('components.schemas.WebsiteLeadRequest.required.0', 'phone')
            ->assertJsonPath('components.securitySchemes.AccountBearerToken.scheme', 'bearer');
    }
}
