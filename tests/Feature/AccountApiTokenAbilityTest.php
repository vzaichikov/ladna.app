<?php

namespace Tests\Feature;

use App\Enums\AccountApiTokenAbility;
use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Models\AccountMembership;
use App\Models\User;
use App\Models\WebsiteLead;
use App\Support\AccountApiTokenAbilityAuthorizer;
use App\Support\AccountApiTokenIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountApiTokenAbilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_token_with_selected_abilities(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'MCP bot',
                'abilities' => [
                    AccountApiTokenAbility::McpRead->value,
                    AccountApiTokenAbility::McpBookingsCreate->value,
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'api']));

        $apiToken = AccountApiToken::whereBelongsTo($account)->firstOrFail();

        $this->assertTrue($apiToken->hasAbility(AccountApiTokenAbility::McpRead));
        $this->assertTrue($apiToken->hasAbility(AccountApiTokenAbility::McpBookingsCreate));
        $this->assertFalse($apiToken->hasAbility(AccountApiTokenAbility::WebsiteLeadsCreate));
    }

    public function test_website_lead_api_requires_website_lead_ability(): void
    {
        $account = Account::factory()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'MCP bot', [
            AccountApiTokenAbility::McpRead,
        ]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/api/v1/website-leads', [
                'phone' => '+380671112233',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', __('app.api_token_forbidden'));

        $this->assertSame(0, WebsiteLead::whereBelongsTo($account)->count());
    }

    public function test_token_ability_permissions_are_centralized_and_match_studio_permissions(): void
    {
        $authorizer = app(AccountApiTokenAbilityAuthorizer::class);

        $this->assertSame(StudioPermission::ManageWebsiteLeads, $authorizer->requiredPermission(AccountApiTokenAbility::WebsiteLeadsCreate));
        $this->assertSame(StudioPermission::ManageStudioSettings, $authorizer->requiredPermission(AccountApiTokenAbility::McpRead));
        $this->assertSame(StudioPermission::ManageStudioSettings, $authorizer->requiredPermission(AccountApiTokenAbility::McpLogicRead));
        $this->assertSame(StudioPermission::ManageBookings, $authorizer->requiredPermission(AccountApiTokenAbility::McpBookingsCreate));
        $this->assertSame(StudioPermission::ManageBookings, $authorizer->requiredPermission(AccountApiTokenAbility::McpBookingsCancel));
        $this->assertSame(StudioPermission::ManageClients, $authorizer->requiredPermission(AccountApiTokenAbility::McpCustomersRead));
        $this->assertSame(StudioPermission::ManageCustomerClassPasses, $authorizer->requiredPermission(AccountApiTokenAbility::McpClassPassesRead));
        $this->assertSame(StudioPermission::ViewStudioFinancialReports, $authorizer->requiredPermission(AccountApiTokenAbility::McpPaymentsRead));
        $this->assertSame(StudioPermission::ManageStudioCashflow, $authorizer->requiredPermission(AccountApiTokenAbility::McpCashflowRead));
        $this->assertSame(StudioPermission::ManageStudioPayroll, $authorizer->requiredPermission(AccountApiTokenAbility::McpPayrollRead));
        $this->assertSame(StudioPermission::ManageEvents, $authorizer->requiredPermission(AccountApiTokenAbility::McpEventsRead));
        $this->assertSame(StudioPermission::ManageFestivals, $authorizer->requiredPermission(AccountApiTokenAbility::FestivalBattlesOperate));
        $this->assertTrue(AccountApiTokenAbility::FestivalBattlesOperate->mutatesAccountData());
    }

    public function test_staff_cannot_submit_an_ability_outside_current_permissions(): void
    {
        $account = Account::factory()->create();
        $staff = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($staff)
            ->create([
                'role' => AccountRole::Admin->value,
                'permissions' => [
                    StudioPermission::ManageStudioSettings->value,
                    StudioPermission::ViewStudioFinancialReports->value,
                ],
            ]);

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Forged event token',
                'abilities' => [AccountApiTokenAbility::McpEventsRead->value],
            ])
            ->assertSessionHasErrors('abilities.0');

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Forged cashbox token',
                'abilities' => [AccountApiTokenAbility::McpCashflowRead->value],
            ])
            ->assertSessionHasErrors('abilities.0');

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Forged payroll token',
                'abilities' => [AccountApiTokenAbility::McpPayrollRead->value],
            ])
            ->assertSessionHasErrors('abilities.0');

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Allowed payment token',
                'abilities' => [AccountApiTokenAbility::McpPaymentsRead->value],
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'api']));

        $this->assertDatabaseMissing('account_api_tokens', [
            'account_id' => $account->id,
            'name' => 'Forged event token',
        ]);
        $this->assertTrue(
            AccountApiToken::query()
                ->whereBelongsTo($account)
                ->where('name', 'Allowed payment token')
                ->firstOrFail()
                ->hasAbility(AccountApiTokenAbility::McpPaymentsRead),
        );
    }

    public function test_omitting_abilities_cannot_bypass_default_website_lead_permission(): void
    {
        $account = Account::factory()->create();
        $staff = User::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($staff)
            ->create([
                'role' => AccountRole::Admin->value,
                'permissions' => [StudioPermission::ManageStudioSettings->value],
            ]);

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Crafted default token',
            ])
            ->assertSessionHasErrors('abilities.0');

        $this->assertDatabaseMissing('account_api_tokens', [
            'account_id' => $account->id,
            'name' => 'Crafted default token',
        ]);
    }

    public function test_platform_admin_can_create_payment_and_event_token_through_global_bypass(): void
    {
        $account = Account::factory()->create();
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Platform operations',
                'abilities' => [
                    AccountApiTokenAbility::McpPaymentsRead->value,
                    AccountApiTokenAbility::McpCashflowRead->value,
                    AccountApiTokenAbility::McpPayrollRead->value,
                    AccountApiTokenAbility::McpEventsRead->value,
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.integrations.index', [$account, 'tab' => 'api']));

        $token = AccountApiToken::query()
            ->whereBelongsTo($account)
            ->where('name', 'Platform operations')
            ->firstOrFail();

        $this->assertTrue($token->hasAbility(AccountApiTokenAbility::McpPaymentsRead));
        $this->assertTrue($token->hasAbility(AccountApiTokenAbility::McpCashflowRead));
        $this->assertTrue($token->hasAbility(AccountApiTokenAbility::McpPayrollRead));
        $this->assertTrue($token->hasAbility(AccountApiTokenAbility::McpEventsRead));
    }
}
