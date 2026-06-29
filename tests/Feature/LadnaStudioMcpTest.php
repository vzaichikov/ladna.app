<?php

namespace Tests\Feature;

use App\Enums\AccountApiTokenAbility;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\McpToolInvocation;
use App\Models\ScheduledClass;
use App\Support\AccountApiTokenIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LadnaStudioMcpTest extends TestCase
{
    use DatabaseTransactions;

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
                'query' => 'schedule',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.results.0.slug', 'schedule');

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-owner-help-page', [
                'slug' => 'schedule',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.slug', 'schedule')
            ->assertJsonPath('result.structuredContent.title', config('help.pages.schedule.title'));
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
