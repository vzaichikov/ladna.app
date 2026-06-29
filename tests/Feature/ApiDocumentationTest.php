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
            ->assertSee('/mcp/ladna-studio')
            ->assertSee('get-class-bookings-for-day')
            ->assertSee('search-owner-help')
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
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/schedule.get.responses.402.$ref', '#/components/responses/SubscriptionExpired')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/price.get.tags.0', 'Public prices')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/price.get.responses.402.$ref', '#/components/responses/SubscriptionExpired')
            ->assertJsonPath('paths./api/v1/website-leads.post.tags.0', 'Website leads')
            ->assertJsonPath('paths./api/v1/website-leads.post.summary', 'Creates a website lead for the studio identified by the bearer token with the website_leads:create ability.')
            ->assertJsonPath('paths./api/v1/website-leads.post.security.0.AccountBearerToken', [])
            ->assertJsonPath('paths./api/v1/website-leads.post.responses.402.$ref', '#/components/responses/SubscriptionExpired')
            ->assertJsonPath('paths./mcp/ladna-studio.post.tags.0', 'MCP')
            ->assertJsonPath('paths./mcp/ladna-studio.post.security.0.AccountBearerToken', [])
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.class_bookings_for_day.value.params.name', 'get-class-bookings-for-day')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.owner_help_search.value.params.name', 'search-owner-help')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.owner_help_search.value.params.arguments.query', 'як додати клієнта')
            ->assertJsonPath('paths./mcp/ladna-studio.post.responses.401.$ref', '#/components/responses/Unauthorized')
            ->assertJsonPath('components.schemas.McpToolCallRequest.properties.params.properties.name.enum.2', 'get-class-bookings-for-day')
            ->assertJsonPath('components.schemas.McpOwnerHelpSearchResult.properties.score.type', 'integer')
            ->assertJsonPath('components.schemas.McpOwnerHelpFragment.properties.steps.maxItems', 6)
            ->assertJsonPath('components.schemas.WebsiteLeadRequest.required.0', 'phone')
            ->assertJsonPath('components.schemas.ClassPassPlan.properties.total_validity_days.type', 'integer')
            ->assertJsonPath('components.schemas.ClassPassPlan.properties.segment.anyOf.0.$ref', '#/components/schemas/ClassPassSegment')
            ->assertJsonPath('components.schemas.ClassPassSegment.properties.schedule_kind.type', 'string')
            ->assertJsonPath('components.schemas.PriceGroup.properties.schedule_kind.type', 'string')
            ->assertJsonPath('components.schemas.WebsiteLead.allOf.1.properties.created_at.description', 'Lead creation time in the bearer token account timezone.')
            ->assertJsonPath('components.schemas.WebsiteLead.allOf.1.properties.created_at.example', '2026-06-24T11:48:00+03:00')
            ->assertJsonPath('components.responses.SubscriptionExpired.content.application/json.schema.properties.code.example', 'subscription_expired')
            ->assertJsonPath('components.responses.SubscriptionExpired.content.application/json.schema.properties.code.enum.1', 'demo_payment_required')
            ->assertJsonPath('components.securitySchemes.AccountBearerToken.scheme', 'bearer')
            ->assertJsonPath('components.securitySchemes.AccountBearerToken.description', 'Bearer token issued in studio settings. Website lead intake requires website_leads:create. MCP tools require their documented mcp:* abilities and always resolve account scope from this token.');
    }
}
