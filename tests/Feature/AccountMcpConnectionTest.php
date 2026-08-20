<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\McpOAuthConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AccountMcpConnectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_staff_member_sees_the_exact_studio_link_and_only_their_connections(): void
    {
        $trainer = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = Account::factory()->create(['slug' => 'my-dance-studio']);
        AccountMembership::factory()->for($account)->for($trainer)->create(['role' => AccountRole::Trainer->value]);
        AccountMembership::factory()->for($account)->for($otherUser)->create(['role' => AccountRole::Receptionist->value]);
        $trainerConnection = McpOAuthConnection::factory()->create([
            'account_id' => $account->id,
            'user_id' => $trainer->id,
            'client_name' => 'Trainer ChatGPT',
        ]);
        McpOAuthConnection::factory()->create([
            'account_id' => $account->id,
            'user_id' => $otherUser->id,
            'client_name' => 'Reception Claude',
        ]);

        $this->actingAs($trainer)
            ->get(route('dashboard.accounts.connections.index', $account))
            ->assertOk()
            ->assertSee(route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]))
            ->assertSee(route('mcp.connection-guide.show', $account))
            ->assertSee(route('mcp.connection-guide.markdown', $account))
            ->assertSee($trainerConnection->client_name)
            ->assertDontSee('Reception Claude')
            ->assertDontSee(__('app.connections_tab_api'))
            ->assertSee(__('app.mcp_connection_permission_note'));

        $this->actingAs($trainer)
            ->get(route('dashboard.accounts.connections.index', [$account, 'tab' => 'api']))
            ->assertForbidden();
    }

    public function test_owner_sees_both_connection_tabs_and_legacy_pages_redirect_to_the_hub(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.connections.index', $account))
            ->assertOk()
            ->assertSee(__('app.connections_tab_ai'))
            ->assertSee(__('app.connections_tab_api'))
            ->assertSee(route('dashboard.index'))
            ->assertSee(route('dashboard.accounts.show', $account));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.connections.index', [$account, 'tab' => 'api']))
            ->assertOk()
            ->assertSee(__('app.api_tokens'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.mcp-connections.index', $account))
            ->assertRedirect(route('dashboard.accounts.connections.index', $account));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']))
            ->assertRedirect(route('dashboard.accounts.connections.index', [$account, 'tab' => 'api']));
    }

    public function test_studio_settings_manager_can_disconnect_a_team_connection_and_revoke_only_that_users_tokens(): void
    {
        $owner = User::factory()->create();
        $trainer = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        AccountMembership::factory()->for($account)->for($trainer)->create(['role' => AccountRole::Trainer->value]);
        AccountMembership::factory()->for($account)->for($otherUser)->create(['role' => AccountRole::Trainer->value]);
        $client = Client::factory()->asPublic()->create(['account_id' => $account->id]);
        $connection = McpOAuthConnection::factory()->create([
            'account_id' => $account->id,
            'user_id' => $trainer->id,
            'oauth_client_id' => $client->id,
            'client_name' => 'Trainer ChatGPT',
        ]);
        $trainerToken = Passport::token()->forceFill([
            'id' => str_repeat('a', 80),
            'user_id' => $trainer->id,
            'client_id' => $client->id,
            'scopes' => ['mcp:use'],
            'revoked' => true,
            'expires_at' => now()->addHour(),
        ]);
        $trainerToken->save();
        Passport::refreshToken()->forceFill([
            'id' => str_repeat('b', 80),
            'access_token_id' => $trainerToken->id,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ])->save();
        $otherToken = Passport::token()->forceFill([
            'id' => str_repeat('c', 80),
            'user_id' => $otherUser->id,
            'client_id' => $client->id,
            'scopes' => ['mcp:use'],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);
        $otherToken->save();

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.connections.mcp-connections.destroy', [$account, $connection]))
            ->assertRedirect(route('dashboard.accounts.connections.index', $account))
            ->assertSessionHas('status', __('app.mcp_connection_removed'));

        $this->assertNotNull($connection->fresh()->revoked_at);
        $this->assertTrue((bool) $trainerToken->fresh()->revoked);
        $this->assertTrue((bool) Passport::refreshToken()->newQuery()->findOrFail(str_repeat('b', 80))->revoked);
        $this->assertFalse((bool) $otherToken->fresh()->revoked);
    }

    public function test_staff_member_cannot_disconnect_another_persons_connection(): void
    {
        $trainer = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = Account::factory()->create();
        AccountMembership::factory()->for($account)->for($trainer)->create(['role' => AccountRole::Trainer->value]);
        AccountMembership::factory()->for($account)->for($otherUser)->create(['role' => AccountRole::Trainer->value]);
        $connection = McpOAuthConnection::factory()->create([
            'account_id' => $account->id,
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($trainer)
            ->delete(route('dashboard.accounts.connections.mcp-connections.destroy', [$account, $connection]))
            ->assertForbidden();

        $this->assertNull($connection->fresh()->revoked_at);
    }

    public function test_platform_admin_without_studio_membership_cannot_view_or_revoke_connections(): void
    {
        $platformAdmin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $account = Account::factory()->create();
        $connection = McpOAuthConnection::factory()->create(['account_id' => $account->id]);

        $this->actingAs($platformAdmin)
            ->get(route('dashboard.accounts.connections.index', $account))
            ->assertForbidden();

        $this->actingAs($platformAdmin)
            ->delete(route('dashboard.accounts.connections.mcp-connections.destroy', [$account, $connection]))
            ->assertForbidden();

        $this->assertNull($connection->fresh()->revoked_at);
    }

    public function test_connection_from_another_studio_cannot_be_disconnected_through_this_studio(): void
    {
        $owner = User::factory()->create();
        $firstAccount = Account::factory()->create();
        $secondAccount = Account::factory()->create();
        $firstAccount->addOwner($owner);
        $secondAccount->addOwner($owner);
        $connection = McpOAuthConnection::factory()->create(['account_id' => $secondAccount->id]);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.connections.mcp-connections.destroy', [$firstAccount, $connection]))
            ->assertNotFound();

        $this->assertNull($connection->fresh()->revoked_at);
    }
}
