<?php

namespace Tests\Feature;

use App\Enums\AccountApiTokenAbility;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\Location;
use App\Models\McpToolInvocation;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Support\AccountApiTokenIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LadnaStudioMcpTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mcp_endpoint_requires_bearer_token(): void
    {
        $this->postJson('/mcp/ladna-studio', $this->toolPayload('get-studio-profile'))
            ->assertUnauthorized()
            ->assertJsonPath('message', __('app.api_token_missing'));
    }

    public function test_mcp_endpoint_rejects_invalid_bearer_token(): void
    {
        $this->withToken('not-a-real-token')
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-studio-profile'))
            ->assertUnauthorized()
            ->assertJsonPath('message', __('app.api_token_invalid'));
    }

    public function test_mcp_get_studio_profile_uses_bearer_account_scope(): void
    {
        $account = Account::factory()->create([
            'name' => 'Owner Studio',
            'slug' => 'owner-studio',
            'timezone' => 'Europe/Kyiv',
        ]);
        Location::factory()->for($account)->create([
            'name' => 'Main',
            'address' => 'Kyiv',
        ]);
        Location::factory()->for(Account::factory())->create(['name' => 'Other tenant']);
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'MCP', [
            AccountApiTokenAbility::McpRead,
        ]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-studio-profile'))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.studio.name', 'Owner Studio')
            ->assertJsonPath('result.structuredContent.locations.0.name', 'Main')
            ->assertJsonMissingPath('result.structuredContent.locations.1');

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_id' => $account->id,
            'account_api_token_id' => $apiToken->id,
            'tool_name' => 'get-studio-profile',
            'required_ability' => AccountApiTokenAbility::McpRead->value,
            'status' => 'succeeded',
        ]);
    }

    public function test_mcp_describe_ladna_skills_returns_curated_capabilities(): void
    {
        $account = Account::factory()->create([
            'name' => 'Owner Studio',
            'slug' => 'owner-studio',
            'timezone' => 'Europe/Kyiv',
        ]);
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'MCP', [
            AccountApiTokenAbility::McpRead,
        ]);

        $response = $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('describe-ladna-skills', [
                'channel' => 'dashboard_chat',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.assistant.name', 'Ladna')
            ->assertJsonPath('result.structuredContent.assistant.current_channel', 'dashboard_chat')
            ->assertJsonPath('result.structuredContent.studio_scope.name', 'Owner Studio')
            ->assertJsonPath('result.structuredContent.read_capabilities.0.key', 'studio_profile')
            ->assertJsonPath('result.structuredContent.guided_dialogs.0.key', 'create_group_booking_dialog')
            ->assertJsonPath('result.structuredContent.guided_dialogs.0.confirmation_required', true)
            ->assertJsonPath('result.structuredContent.mutating_actions.0.key', 'create-booking')
            ->assertJsonPath('result.structuredContent.mutating_actions.0.confirmation_required', true);

        $capabilities = $response->json('result.structuredContent.read_capabilities');

        $this->assertTrue(collect($capabilities)->contains(
            fn (array $capability): bool => in_array('get-class-bookings-for-day', $capability['tools'] ?? [], true)
                && $capability['required_ability'] === AccountApiTokenAbility::McpCustomersRead->value
        ));

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_id' => $account->id,
            'account_api_token_id' => $apiToken->id,
            'tool_name' => 'describe-ladna-skills',
            'required_ability' => AccountApiTokenAbility::McpRead->value,
            'status' => 'succeeded',
        ]);
    }

    public function test_mcp_class_counts_for_day_count_only_token_account_classes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:00:00', 'UTC'));

        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $otherAccount = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $location = Location::factory()->for($account)->create(['name' => 'Main']);
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => ScheduleKind::GroupClass->value]);
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'MCP', [
            AccountApiTokenAbility::McpRead,
        ]);

        ScheduledClass::factory()->for($account)->for($location)->for($classType)->create([
            'room_id' => null,
            'trainer_id' => null,
            'starts_at' => Carbon::parse('2026-06-28 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-28 11:00:00', 'UTC'),
        ]);
        ScheduledClass::factory()->for($otherAccount)->create([
            'starts_at' => Carbon::parse('2026-06-28 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-06-28 11:00:00', 'UTC'),
        ]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-class-counts-for-day', [
                'date' => '2026-06-28',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.total', 1)
            ->assertJsonPath('result.structuredContent.by_location.0.location_name', 'Main')
            ->assertJsonPath('result.structuredContent.by_schedule_kind.group_class', 1);

        Carbon::setTestNow();
    }

    public function test_mcp_class_bookings_for_day_returns_trainer_and_customer_details_in_token_scope(): void
    {
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $otherAccount = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $location = Location::factory()->for($account)->create(['name' => 'Podil']);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Big Hall']);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Marta']);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Pole Beginner',
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($trainer)
            ->for($classType)
            ->create([
                'title' => 'Pole Beginner',
                'capacity' => 6,
                'starts_at' => Carbon::parse('2026-06-30 10:00:00', 'Europe/Kyiv')->timezone('UTC'),
                'ends_at' => Carbon::parse('2026-06-30 11:00:00', 'Europe/Kyiv')->timezone('UTC'),
            ]);
        $anna = Customer::factory()->for($account)->create(['name' => 'Anna Client']);
        $olena = Customer::factory()->for($account)->create(['name' => 'Olena Client']);
        $annaBooking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($anna, 'customer')
            ->create(['status' => ClassBookingStatus::Booked->value]);
        ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($olena, 'customer')
            ->create(['status' => ClassBookingStatus::Booked->value]);
        $classPass = CustomerClassPass::factory()
            ->for($account)
            ->for($anna, 'customer')
            ->create(['code' => 'ABCD-1234', 'plan_name' => 'BASE 8']);
        CustomerClassPassReservation::factory()
            ->for($account)
            ->for($classPass)
            ->for($annaBooking)
            ->for($scheduledClass)
            ->create();
        ScheduledClass::factory()->for($otherAccount)->create([
            'starts_at' => Carbon::parse('2026-06-30 10:00:00', 'Europe/Kyiv')->timezone('UTC'),
            'ends_at' => Carbon::parse('2026-06-30 11:00:00', 'Europe/Kyiv')->timezone('UTC'),
        ]);
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'MCP customers', [
            AccountApiTokenAbility::McpCustomersRead,
        ]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-class-bookings-for-day', [
                'date' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.total_classes', 1)
            ->assertJsonPath('result.structuredContent.total_bookings', 2)
            ->assertJsonPath('result.structuredContent.classes.0.scheduled_class_id', $scheduledClass->id)
            ->assertJsonPath('result.structuredContent.classes.0.time_range', '10:00-11:00')
            ->assertJsonPath('result.structuredContent.classes.0.trainer.name', 'Marta')
            ->assertJsonPath('result.structuredContent.classes.0.location.name', 'Podil')
            ->assertJsonPath('result.structuredContent.classes.0.room.name', 'Big Hall')
            ->assertJsonPath('result.structuredContent.classes.0.bookings_count', 2)
            ->assertJsonPath('result.structuredContent.classes.0.available_spots', 4)
            ->assertJsonPath('result.structuredContent.classes.0.bookings.0.customer.name', 'Anna Client')
            ->assertJsonPath('result.structuredContent.classes.0.bookings.0.class_pass.plan_name', 'BASE 8')
            ->assertJsonPath('result.structuredContent.classes.0.bookings.1.customer.name', 'Olena Client');

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_id' => $account->id,
            'account_api_token_id' => $apiToken->id,
            'tool_name' => 'get-class-bookings-for-day',
            'required_ability' => AccountApiTokenAbility::McpCustomersRead->value,
            'status' => 'succeeded',
        ]);
    }

    public function test_mcp_class_bookings_for_day_requires_customer_read_ability(): void
    {
        $account = Account::factory()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'MCP read only', [
            AccountApiTokenAbility::McpRead,
        ]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-class-bookings-for-day', [
                'date' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', __('app.api_token_forbidden'));

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_id' => $account->id,
            'account_api_token_id' => $apiToken->id,
            'tool_name' => 'get-class-bookings-for-day',
            'required_ability' => AccountApiTokenAbility::McpCustomersRead->value,
            'status' => 'denied',
        ]);
    }

    public function test_mcp_endpoint_requires_mcp_read_ability(): void
    {
        $account = Account::factory()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Website', [
            AccountApiTokenAbility::WebsiteLeadsCreate,
        ]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-studio-profile'))
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', __('app.api_token_forbidden'));

        $this->assertFalse(McpToolInvocation::whereBelongsTo($account)->exists());
    }

    public function test_mcp_owner_help_tools_read_curated_help_config(): void
    {
        $account = Account::factory()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'MCP', [
            AccountApiTokenAbility::McpRead,
        ]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('search-owner-help', [
                'query' => 'як додати клієнта',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.results.0.slug', 'customers-bookings')
            ->assertJsonPath('result.structuredContent.results.0.matched_sections.0', 'Як додати клієнта вручну')
            ->assertJsonPath('result.structuredContent.results.0.fragments.0.steps.1', 'Натисніть Додати клієнта.');

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('search-owner-help', [
                'query' => 'як записати людину на заняття',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.results.0.slug', 'customers-bookings')
            ->assertJsonPath('result.structuredContent.results.0.matched_sections.0', 'Як записати людину на групове заняття');

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('search-owner-help', [
                'query' => 'дівчата приходять без запису їх потім додати після тренування',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.results.0.slug', 'case-walk-in-after-training')
            ->assertJsonPath('result.structuredContent.results.0.matched_sections.0', 'Якщо клієнт прийшов без запису');

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('search-owner-help', [
                'query' => 'рецепт пирога',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.results', []);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-owner-help-page', [
                'slug' => 'customers-bookings',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.slug', 'customers-bookings')
            ->assertJsonPath('result.structuredContent.title', config('help.pages.customers-bookings.title'));
    }

    public function test_mcp_business_logic_reference_is_allow_listed_and_logic_gated(): void
    {
        $account = Account::factory()->create();
        $readOnlyToken = app(AccountApiTokenIssuer::class)->issue($account, 'Read MCP', [
            AccountApiTokenAbility::McpRead,
        ]);
        $logicToken = app(AccountApiTokenIssuer::class)->issue($account, 'Logic MCP', [
            AccountApiTokenAbility::McpLogicRead,
        ]);

        $this->withToken($readOnlyToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-business-logic-reference', [
                'key' => 'quick_booking',
            ]))
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', __('app.api_token_forbidden'));

        $this->withToken($logicToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-business-logic-reference', [
                'key' => 'quick_booking',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.reference.symbol', 'App\\Actions\\CreateQuickBooking::execute');

        $this->withToken($logicToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-business-logic-reference', [
                'key' => '../../.env',
            ]))
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolPayload(string $name, array $arguments = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ];
    }
}
