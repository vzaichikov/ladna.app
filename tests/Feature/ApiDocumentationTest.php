<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_api_documentation_defaults_to_the_public_api_tab(): void
    {
        $response = $this->get(route('api-docs.show'));

        $response
            ->assertOk()
            ->assertSee(__('app.api_documentation'))
            ->assertSee('data-api-docs-tab="public"', false)
            ->assertSee('data-api-docs-tab="restricted"', false)
            ->assertSee('data-api-docs-tab="mcp"', false)
            ->assertSee('data-api-docs-tab="connect"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('/api/v1/public/{accountSlug}/{locationSlug}/schedule')
            ->assertSee('/api/v1/public/{accountSlug}/{locationSlug}/schedule/week')
            ->assertSee('/api/v1/public/{accountSlug}/{locationSlug}/price')
            ->assertSee('/api/v1/public/{accountSlug}/{locationSlug}/classes')
            ->assertDontSee('/api/v1/mobile/auth/staff/login')
            ->assertDontSee('/api/v1/website-leads')
            ->assertDontSee('/mcp/ladna-studio')
            ->assertSee('PHP')
            ->assertSee('Python')
            ->assertSee('JS')
            ->assertDontSee('Authorization: Bearer ladna_your_token');
    }

    public function test_unknown_api_documentation_tab_falls_back_to_public_api(): void
    {
        $this->get(route('api-docs.show', ['tab' => 'unknown']))
            ->assertOk()
            ->assertSee(__('app.api_docs_public_title'))
            ->assertSee('/api/v1/public/{accountSlug}/{locationSlug}/price')
            ->assertDontSee('/mcp/ladna-studio');
    }

    public function test_restricted_api_tab_renders_only_non_public_non_mcp_endpoints(): void
    {
        $this->get(route('api-docs.show', ['tab' => 'restricted']))
            ->assertOk()
            ->assertSee(__('app.api_docs_restricted_title'))
            ->assertSee('/api/v1/mobile/auth/staff/login')
            ->assertSee('/api/v1/mobile/schedule')
            ->assertSee('/api/v1/mobile/bookings/{classBooking}')
            ->assertSee('Cancels a booking before the cutoff and releases the class-pass session')
            ->assertSee('/api/v1/mobile/customer/profile/phone/send')
            ->assertSee('/api/v1/mobile/customer/profile/phone/verify')
            ->assertSee('/api/v1/website-leads')
            ->assertSee('/api/v1/festival-payments/{provider}/callbacks')
            ->assertSee('/api/v1/festival-battles/matches')
            ->assertSee('/api/v1/festival-battles/matches/{match}/audience-score')
            ->assertSee('Receives a payment provider callback for a Festival performance charge or spectator admission order')
            ->assertDontSee('/api/v1/public/{accountSlug}/{locationSlug}/schedule')
            ->assertDontSee('/mcp/ladna-studio')
            ->assertSee(__('app.api_docs_example_website_lead'))
            ->assertSee(__('app.api_docs_example_festival_battle_score'))
            ->assertSee('PHP')
            ->assertSee('Python')
            ->assertSee('JS')
            ->assertSee('Authorization: Bearer ladna_your_token');
    }

    public function test_mcp_tab_renders_only_the_mcp_endpoint_and_examples(): void
    {
        $this->get(route('api-docs.show', ['tab' => 'mcp']))
            ->assertOk()
            ->assertSee(__('app.api_docs_mcp_title'))
            ->assertSee('/mcp/ladna-studio')
            ->assertSee('describe-ladna-skills')
            ->assertSee('get-class-bookings-for-day')
            ->assertSee('search-customers')
            ->assertSee('investigate-customer-booking-ledger')
            ->assertSee('search-owner-help')
            ->assertSee('get-payment-overview')
            ->assertSee('search-payments')
            ->assertSee('get-financial-report')
            ->assertSee('get-cashbox-overview')
            ->assertSee('get-earnings-report')
            ->assertSee('get-rental-report')
            ->assertSee('get-payroll-overview')
            ->assertSee('get-events-overview')
            ->assertSee('get-event-summary')
            ->assertSee(__('app.api_docs_mcp_oauth_title'))
            ->assertSee(__('app.api_docs_mcp_catalog_title'))
            ->assertSee(__('app.api_docs_mcp_group_finance'))
            ->assertSee(__('app.api_docs_mcp_service_title'))
            ->assertSee('PHP')
            ->assertSee('Python')
            ->assertSee('JS')
            ->assertSee('Authorization: Bearer ladna_your_token')
            ->assertDontSee('/api/v1/public/{accountSlug}/{locationSlug}/schedule')
            ->assertDontSee('/api/v1/website-leads')
            ->assertDontSee('/api/v1/mobile/auth/staff/login');
    }

    public function test_connect_tab_renders_plain_language_owner_instructions(): void
    {
        $this->get(route('api-docs.show', ['tab' => 'connect']))
            ->assertOk()
            ->assertSee(__('app.api_docs_connect_title'))
            ->assertSee(__('app.api_docs_connect_chatgpt_title'))
            ->assertSee(__('app.api_docs_connect_step_1_title'))
            ->assertSee(__('app.api_docs_connect_step_2_title'))
            ->assertSee(__('app.api_docs_connect_step_3_title'))
            ->assertSee(__('app.api_docs_connect_step_4_title'))
            ->assertSee(__('app.api_docs_connect_test_prompt'))
            ->assertSee(route('mcp.ladna-studio'))
            ->assertSee('data-copy-source', false)
            ->assertDontSee(__('app.openapi_json'))
            ->assertDontSee('<pre', false)
            ->assertDontSee('Authorization: Bearer ladna_your_token')
            ->assertDontSee('/api/v1/public/{accountSlug}/{locationSlug}/schedule')
            ->assertDontSee('/api/v1/website-leads');
    }

    public function test_openapi_json_documents_public_and_lead_endpoints(): void
    {
        $response = $this->getJson(route('api-docs.openapi'));

        $response
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/schedule.get.tags.0', 'Public schedule')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/schedule.get.responses.402.$ref', '#/components/responses/SubscriptionExpired')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/schedule/week.get.tags.0', 'Public schedule')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/schedule/week.get.parameters.2.name', 'date')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/schedule/week.get.responses.200.content.application/json.schema.properties.data.items.$ref', '#/components/schemas/PublicScheduleDay')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/schedule/week.get.responses.422.$ref', '#/components/responses/ValidationError')
            ->assertJsonPath('components.schemas.ScheduledClass.properties.color.type', 'string')
            ->assertJsonPath('components.schemas.ScheduledClass.properties.text_color.type', 'string')
            ->assertJsonPath('components.schemas.Trainer.properties.photo_url.type.0', 'string')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/price.get.tags.0', 'Public prices')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/price.get.responses.402.$ref', '#/components/responses/SubscriptionExpired')
            ->assertJsonPath('paths./api/v1/public/{accountSlug}/{locationSlug}/price.get.responses.429.$ref', '#/components/responses/TooManyRequests')
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
            ->assertJsonPath('paths./api/v1/mobile/bookings/{classBooking}.delete.summary', 'Cancels a booking before the cutoff and releases the class-pass session while keeping the booking in history. Staff need manage_bookings.')
            ->assertJsonPath('paths./api/v1/mobile/bookings/{classBooking}.delete.security.0.MobileBearerToken', [])
            ->assertJsonPath('paths./api/v1/mobile/bookings/{classBooking}.delete.responses.200.content.application/json.schema.properties.data.$ref', '#/components/schemas/MobileClassBooking')
            ->assertJsonPath('paths./api/v1/mobile/device-tokens.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/MobileDeviceTokenRequest')
            ->assertJsonPath('paths./api/v1/website-leads.post.tags.0', 'Website leads')
            ->assertJsonPath('paths./api/v1/website-leads.post.summary', 'Creates a website lead for the studio identified by the bearer token with the website_leads:create ability.')
            ->assertJsonPath('paths./api/v1/website-leads.post.security.0.AccountBearerToken', [])
            ->assertJsonPath('paths./api/v1/website-leads.post.responses.402.$ref', '#/components/responses/SubscriptionExpired')
            ->assertJsonPath('paths./api/v1/website-leads.post.responses.423.$ref', '#/components/responses/DemoReadOnly')
            ->assertJsonPath('paths./api/v1/festival-payments/{provider}/callbacks.post.tags.0', 'Festival payments')
            ->assertJsonPath('paths./api/v1/festival-payments/{provider}/callbacks.post.security', [])
            ->assertJsonPath('paths./api/v1/festival-payments/{provider}/callbacks.post.parameters.0.name', 'provider')
            ->assertJsonPath('paths./api/v1/festival-payments/{provider}/callbacks.post.requestBody.content.application/json.schema.additionalProperties', true)
            ->assertJsonPath('paths./api/v1/festival-payments/{provider}/callbacks.post.responses.400.description', 'The callback signature, order identifier, amount, currency, or state is invalid.')
            ->assertJsonPath('paths./api/v1/festival-battles/matches.get.tags.0', 'Festival battles')
            ->assertJsonPath('paths./api/v1/festival-battles/matches.get.security.0.AccountBearerToken', [])
            ->assertJsonPath('paths./api/v1/festival-battles/matches/{match}.get.responses.200.content.application/json.schema.properties.data.$ref', '#/components/schemas/FestivalBattleMatch')
            ->assertJsonPath('paths./api/v1/festival-battles/matches/{match}/audience-score.put.requestBody.content.application/json.schema.$ref', '#/components/schemas/FestivalBattleAudienceScoreRequest')
            ->assertJsonPath('paths./api/v1/festival-battles/matches/{match}/audience-score.put.responses.202.content.application/json.schema.properties.data.$ref', '#/components/schemas/FestivalBattleMatch')
            ->assertJsonPath('paths./api/v1/festival-battles/matches/{match}/audience-score.put.responses.409.content.application/json.schema.$ref', '#/components/schemas/FestivalBattleConflictResponse')
            ->assertJsonPath('paths./api/v1/festival-battles/matches/{match}/audience-score.put.responses.423.$ref', '#/components/responses/DemoReadOnly')
            ->assertJsonPath('components.schemas.FestivalBattleAudienceScoreRequest.properties.audience_score_a.maximum', 1000000)
            ->assertJsonPath('components.schemas.FestivalBattleMatch.properties.state.enum.2', 'jury_decision_required')
            ->assertJsonPath('components.schemas.FestivalBattleMeta.properties.poll_interval_seconds.enum.0', 5)
            ->assertJsonPath('paths./mcp/ladna-studio.post.tags.0', 'MCP')
            ->assertJsonPath('paths./mcp/ladna-studio.post.security.0.AccountBearerToken', [])
            ->assertJsonPath('paths./mcp/ladna-studio/{accountSlug}.post.security.0.LadnaUserOAuth.0', 'mcp:use')
            ->assertJsonPath('paths./mcp/ladna-studio/{accountSlug}.post.parameters.0.name', 'accountSlug')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.class_bookings_for_day.value.params.name', 'get-class-bookings-for-day')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.owner_help_search.value.params.name', 'search-owner-help')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.owner_help_search.value.params.arguments.query', 'як додати клієнта')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.customer_search.value.params.name', 'search-customers')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.customer_booking_investigation.value.params.name', 'investigate-customer-booking-ledger')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.customer_booking_investigation.value.params.arguments.customer_id', 63)
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.customer_booking_investigation.value.params.arguments.as_of', '2026-07-28T20:05:00+03:00')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.customer_booking_investigation.value.params.arguments.source', 'manual')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.describe_ladna_skills.value.params.name', 'describe-ladna-skills')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.describe_ladna_skills.value.params.arguments.channel', 'dashboard_chat')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.payment_overview.value.params.name', 'get-payment-overview')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.payment_overview.value.params.arguments.date_from', '2026-07-01')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.payment_search.value.params.name', 'search-payments')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.payment_search.value.params.arguments.query', 'Коваль')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.financial_report.value.params.name', 'get-financial-report')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.cashbox_overview.value.params.name', 'get-cashbox-overview')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.cashbox_overview.value.params.arguments.currency', 'UAH')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.earnings_report.value.params.name', 'get-earnings-report')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.rental_report.value.params.name', 'get-rental-report')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.payroll_overview.value.params.name', 'get-payroll-overview')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.events_overview.value.params.name', 'get-events-overview')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.events_overview.value.params.arguments.status_bucket', 'upcoming')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.event_summary.value.params.name', 'get-event-summary')
            ->assertJsonPath('paths./mcp/ladna-studio.post.requestBody.content.application/json.examples.event_summary.value.params.arguments.event_id', 42)
            ->assertJsonPath('paths./mcp/ladna-studio.post.responses.401.$ref', '#/components/responses/Unauthorized')
            ->assertJsonPath('paths./mcp/ladna-studio.post.responses.423.$ref', '#/components/responses/DemoReadOnly')
            ->assertJsonPath('components.schemas.McpOwnerHelpSearchResult.properties.score.type', 'integer')
            ->assertJsonPath('components.schemas.McpOwnerHelpFragment.properties.steps.maxItems', 6)
            ->assertJsonPath('components.schemas.McpLadnaSkillsResponse.properties.read_capabilities.items.$ref', '#/components/schemas/McpLadnaCapability')
            ->assertJsonPath('components.schemas.McpLadnaCapability.properties.required_abilities.items.type', 'string')
            ->assertJsonPath('components.schemas.McpLadnaCapability.properties.required_user_permission.example', 'manage_customer_class_passes')
            ->assertJsonPath('components.schemas.McpLadnaActionCapability.properties.confirmation_required.type', 'boolean')
            ->assertJsonPath('components.schemas.McpCustomerBookingInvestigationArguments.properties.as_of.format', 'date-time')
            ->assertJsonPath('components.schemas.McpCustomerBookingInvestigationArguments.properties.source.default', 'manual')
            ->assertJsonPath('components.schemas.McpCustomerBookingInvestigationArguments.properties.source.enum.1', 'online_payment')
            ->assertJsonPath('components.schemas.McpCustomerHistorySummary.properties.counted_bookings_count.type', 'integer')
            ->assertJsonPath('components.schemas.McpTrialEligibility.properties.status.enum.2', 'not_configured')
            ->assertJsonPath('components.schemas.McpTrialEligibility.properties.trial_plans.properties.items.maxItems', 20)
            ->assertJsonPath('components.schemas.McpTrialPlanCandidate.properties.price_cents.type', 'integer')
            ->assertJsonPath('components.schemas.McpManualTrialOverride.properties.status.enum.2', 'actor_permissions_not_evaluated')
            ->assertJsonPath('components.schemas.McpManualTrialOverride.properties.customer_qualifies.type', 'boolean')
            ->assertJsonPath('components.schemas.McpManualTrialOverride.properties.requires_comment.enum.0', true)
            ->assertJsonPath('components.schemas.McpCustomerBookingInvestigationResponse.example.customer_history_summary.counted_bookings_count', 5)
            ->assertJsonPath('components.schemas.McpCustomerBookingInvestigationResponse.example.trial_eligibility.reason_codes.0', 'multiple_existing_bookings')
            ->assertJsonPath('components.schemas.McpCustomerBookingInvestigationResponse.example.trial_eligibility.trial_plans.items.0.price_cents', 25000)
            ->assertJsonPath('components.schemas.McpCustomerBookingInvestigationResponse.example.manual_override.customer_qualifies', true)
            ->assertJsonPath('components.schemas.McpCustomerBookingInvestigationResponse.example.manual_override.available', false)
            ->assertJsonPath('components.schemas.WebsiteLeadRequest.required.0', 'phone')
            ->assertJsonPath('components.schemas.ClassPassPlan.properties.total_validity_days.type', 'integer')
            ->assertJsonPath('components.schemas.ClassPassPlan.properties.checkout_url.format', 'uri')
            ->assertJsonPath('components.schemas.ClassPassPlan.properties.segment.anyOf.0.$ref', '#/components/schemas/ClassPassSegment')
            ->assertJsonPath('components.schemas.ClassPassSegment.properties.schedule_kind.type', 'string')
            ->assertJsonPath('components.schemas.PriceGroup.properties.schedule_kind.type', 'string')
            ->assertJsonPath('components.schemas.WebsiteLead.allOf.1.properties.created_at.description', 'Lead creation time in the bearer token account timezone.')
            ->assertJsonPath('components.schemas.WebsiteLead.allOf.1.properties.created_at.example', '2026-06-24T11:48:00+03:00')
            ->assertJsonPath('components.responses.SubscriptionExpired.content.application/json.schema.properties.code.example', 'subscription_expired')
            ->assertJsonPath('components.responses.SubscriptionExpired.content.application/json.schema.properties.code.enum.1', 'demo_payment_required')
            ->assertJsonPath('components.responses.DemoReadOnly.content.application/json.schema.properties.code.example', 'demo_readonly')
            ->assertJsonPath('components.securitySchemes.AccountBearerToken.scheme', 'bearer')
            ->assertJsonPath('components.securitySchemes.LadnaUserOAuth.flows.authorizationCode.scopes.mcp:use', 'Use the connected Ladna studio tools allowed by the current user role.')
            ->assertJsonPath('components.securitySchemes.MobileBearerToken.bearerFormat', 'Ladna native mobile session token')
            ->assertJsonPath('components.schemas.MobileStaffLoginRequest.required.0', 'email')
            ->assertJsonPath('components.schemas.MobileCustomerProfilePhoneOtpSendRequest.required.0', 'phone')
            ->assertJsonPath('components.schemas.MobileCustomerProfilePhoneOtpVerifyRequest.required.2', 'name')
            ->assertJsonPath('components.schemas.MobileCustomerProfilePhoneOtpVerifyRequest.properties.code.example', '123456')
            ->assertJsonPath('components.responses.ValidationError.content.application/json.schema.properties.code.example', 'phone_verification_required')
            ->assertJsonPath('components.schemas.MobileScheduledClass.properties.customer_booking.type.1', 'null')
            ->assertJsonPath('components.schemas.MobileScheduledClass.properties.schedule_kind.enum.3', 'internal_class')
            ->assertJsonPath('components.schemas.MobileScheduledClass.properties.additional_trainers.type', 'array')
            ->assertJsonPath('components.schemas.MobileScheduledClass.properties.additional_trainers.items.$ref', '#/components/schemas/Trainer')
            ->assertJsonPath('components.schemas.MobileDeviceTokenRequest.properties.provider.enum.0', 'fcm')
            ->assertJsonPath('components.securitySchemes.AccountBearerToken.description', 'Bearer token issued in studio settings. The issuing user must have the studio permission mapped to each selected ability. Website lead intake requires website_leads:create, Festival battle operations require festival_battles:operate, and MCP tools require their documented mcp:* abilities. Account scope always comes from this token; clients never submit an account identifier. Tokens remain account service credentials until revoked.');

        $toolNames = $response->json('components.schemas.McpToolCallRequest.properties.params.properties.name.enum');

        $this->assertContains('describe-ladna-skills', $toolNames);
        $this->assertContains('get-class-bookings-for-day', $toolNames);
        $this->assertContains('search-customers', $toolNames);
        $this->assertContains('investigate-customer-booking-ledger', $toolNames);
        $this->assertContains('get-payment-overview', $toolNames);
        $this->assertContains('search-payments', $toolNames);
        $this->assertContains('get-financial-report', $toolNames);
        $this->assertContains('get-cashbox-overview', $toolNames);
        $this->assertContains('get-earnings-report', $toolNames);
        $this->assertContains('get-rental-report', $toolNames);
        $this->assertContains('get-payroll-overview', $toolNames);
        $this->assertContains('get-events-overview', $toolNames);
        $this->assertContains('get-event-summary', $toolNames);
    }

    public function test_unknown_festival_payment_provider_matches_documented_not_found_response(): void
    {
        $this->postJson(route('api.v1.festival-payments.callbacks', ['provider' => 'not-configured']), [])
            ->assertNotFound()
            ->assertSeeText('Unsupported provider.');
    }
}
