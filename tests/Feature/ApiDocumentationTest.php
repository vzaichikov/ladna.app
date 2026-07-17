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
            ->assertSee('/api/v1/mobile/auth/staff/login')
            ->assertSee('/api/v1/mobile/schedule')
            ->assertSee('/api/v1/mobile/customer/profile/phone/send')
            ->assertSee('/api/v1/mobile/customer/profile/phone/verify')
            ->assertSee('/api/v1/website-leads')
            ->assertSee('/mcp/ladna-studio')
            ->assertSee('describe-ladna-skills')
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
            ->assertJsonPath('paths./api/v1/mobile/auth/staff/login.post.tags.0', 'Mobile auth')
            ->assertJsonPath('paths./api/v1/mobile/auth/staff/login.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/MobileStaffLoginRequest')
            ->assertJsonPath('paths./api/v1/mobile/me.get.security.0.MobileBearerToken', [])
            ->assertJsonPath('paths./api/v1/mobile/schedule.get.tags.0', 'Mobile schedule')
            ->assertJsonPath('paths./api/v1/mobile/schedule.get.security.0.MobileBearerToken', [])
            ->assertJsonPath('paths./api/v1/mobile/customer/profile.put.summary', 'Updates the current customer profile for the session account. If the phone belongs to another customer in this studio, returns a validation response with code phone_verification_required.')
            ->assertJsonPath('paths./api/v1/mobile/customer/profile/phone/send.post.security.0.MobileBearerToken', [])
            ->assertJsonPath('paths./api/v1/mobile/customer/profile/phone/send.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/MobileCustomerProfilePhoneOtpSendRequest')
            ->assertJsonPath('paths./api/v1/mobile/customer/profile/phone/send.post.responses.429.$ref', '#/components/responses/TooManyRequests')
            ->assertJsonPath('paths./api/v1/mobile/customer/profile/phone/resend.post.security.0.MobileBearerToken', [])
            ->assertJsonPath('paths./api/v1/mobile/customer/profile/phone/verify.post.security.0.MobileBearerToken', [])
            ->assertJsonPath('paths./api/v1/mobile/customer/profile/phone/verify.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/MobileCustomerProfilePhoneOtpVerifyRequest')
            ->assertJsonPath('paths./api/v1/mobile/customer/profile/phone/verify.post.responses.200.content.application/json.schema.properties.data.$ref', '#/components/schemas/MobileCustomerSessionResponse')
            ->assertJsonPath('paths./api/v1/mobile/classes/{scheduledClass}/customer-booking.post.responses.201.content.application/json.schema.properties.data.$ref', '#/components/schemas/MobileClassBooking')
            ->assertJsonPath('paths./api/v1/mobile/classes/{scheduledClass}/customer-booking.post.responses.423.$ref', '#/components/responses/DemoReadOnly')
            ->assertJsonPath('paths./api/v1/mobile/device-tokens.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/MobileDeviceTokenRequest')
            ->assertJsonPath('paths./api/v1/website-leads.post.tags.0', 'Website leads')
            ->assertJsonPath('paths./api/v1/website-leads.post.summary', 'Creates a website lead for the studio identified by the bearer token with the website_leads:create ability.')
            ->assertJsonPath('paths./api/v1/website-leads.post.security.0.AccountBearerToken', [])
            ->assertJsonPath('paths./api/v1/website-leads.post.responses.402.$ref', '#/components/responses/SubscriptionExpired')
            ->assertJsonPath('paths./api/v1/website-leads.post.responses.423.$ref', '#/components/responses/DemoReadOnly')
            ->assertJsonPath('paths./mcp/ladna-studio.post.tags.0', 'MCP')
            ->assertJsonPath('paths./mcp/ladna-studio.post.security.0.AccountBearerToken', [])
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.class_bookings_for_day.value.params.name', 'get-class-bookings-for-day')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.owner_help_search.value.params.name', 'search-owner-help')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.owner_help_search.value.params.arguments.query', 'як додати клієнта')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.describe_ladna_skills.value.params.name', 'describe-ladna-skills')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.describe_ladna_skills.value.params.arguments.channel', 'dashboard_chat')
            ->assertJsonPath('paths./mcp/ladna-studio.post.responses.401.$ref', '#/components/responses/Unauthorized')
            ->assertJsonPath('paths./mcp/ladna-studio.post.responses.423.$ref', '#/components/responses/DemoReadOnly')
            ->assertJsonPath('components.schemas.McpOwnerHelpSearchResult.properties.score.type', 'integer')
            ->assertJsonPath('components.schemas.McpOwnerHelpFragment.properties.steps.maxItems', 6)
            ->assertJsonPath('components.schemas.McpLadnaSkillsResponse.properties.read_capabilities.items.$ref', '#/components/schemas/McpLadnaCapability')
            ->assertJsonPath('components.schemas.McpLadnaActionCapability.properties.confirmation_required.type', 'boolean')
            ->assertJsonPath('components.schemas.WebsiteLeadRequest.required.0', 'phone')
            ->assertJsonPath('components.schemas.ClassPassPlan.properties.total_validity_days.type', 'integer')
            ->assertJsonPath('components.schemas.ClassPassPlan.properties.segment.anyOf.0.$ref', '#/components/schemas/ClassPassSegment')
            ->assertJsonPath('components.schemas.ClassPassSegment.properties.schedule_kind.type', 'string')
            ->assertJsonPath('components.schemas.PriceGroup.properties.schedule_kind.type', 'string')
            ->assertJsonPath('components.schemas.WebsiteLead.allOf.1.properties.created_at.description', 'Lead creation time in the bearer token account timezone.')
            ->assertJsonPath('components.schemas.WebsiteLead.allOf.1.properties.created_at.example', '2026-06-24T11:48:00+03:00')
            ->assertJsonPath('components.responses.SubscriptionExpired.content.application/json.schema.properties.code.example', 'subscription_expired')
            ->assertJsonPath('components.responses.SubscriptionExpired.content.application/json.schema.properties.code.enum.1', 'demo_payment_required')
            ->assertJsonPath('components.responses.DemoReadOnly.content.application/json.schema.properties.code.example', 'demo_readonly')
            ->assertJsonPath('components.securitySchemes.AccountBearerToken.scheme', 'bearer')
            ->assertJsonPath('components.securitySchemes.MobileBearerToken.bearerFormat', 'Ladna native mobile session token')
            ->assertJsonPath('components.schemas.MobileStaffLoginRequest.required.0', 'email')
            ->assertJsonPath('components.schemas.MobileCustomerProfilePhoneOtpSendRequest.required.0', 'phone')
            ->assertJsonPath('components.schemas.MobileCustomerProfilePhoneOtpVerifyRequest.required.2', 'name')
            ->assertJsonPath('components.schemas.MobileCustomerProfilePhoneOtpVerifyRequest.properties.code.example', '123456')
            ->assertJsonPath('components.responses.ValidationError.content.application/json.schema.properties.code.example', 'phone_verification_required')
            ->assertJsonPath('components.schemas.MobileScheduledClass.properties.customer_booking.type.1', 'null')
            ->assertJsonPath('components.schemas.MobileDeviceTokenRequest.properties.provider.enum.0', 'fcm')
            ->assertJsonPath('components.securitySchemes.AccountBearerToken.description', 'Bearer token issued in studio settings. Website lead intake requires website_leads:create. MCP tools require their documented mcp:* abilities and always resolve account scope from this token.');

        $toolNames = $response->json('components.schemas.McpToolCallRequest.properties.params.properties.name.enum');

        $this->assertContains('describe-ladna-skills', $toolNames);
        $this->assertContains('get-class-bookings-for-day', $toolNames);
    }
}
