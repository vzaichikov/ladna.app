<?php

namespace Tests\Feature;

use App\Enums\AccountApiTokenAbility;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Models\User;
use App\Models\WebsiteLead;
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
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']));

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
}
