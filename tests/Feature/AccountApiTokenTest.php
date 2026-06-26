<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Models\AccountMembership;
use App\Models\User;
use App\Support\AccountApiTokenIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountApiTokenTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_copy_and_view_account_api_token(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Website form',
            ])
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']))
            ->assertSessionHas('status', __('app.api_token_created'));

        $apiToken = AccountApiToken::whereBelongsTo($account)->firstOrFail();

        $this->assertSame('Website form', $apiToken->name);
        $this->assertSame(64, strlen($apiToken->token_hash));
        $this->assertStringStartsWith('ladna_', $apiToken->tokenValue());

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']))
            ->assertOk()
            ->assertSee('Website form')
            ->assertSee($apiToken->tokenValue());
    }

    public function test_owner_can_regenerate_and_revoke_account_api_token(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Website form');
        $originalTokenHash = $apiToken->token_hash;

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.api-tokens.regenerate', [$account, $apiToken]))
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']))
            ->assertSessionHas('status', __('app.api_token_regenerated'));

        $apiToken->refresh();

        $this->assertNotSame($originalTokenHash, $apiToken->token_hash);
        $this->assertTrue($apiToken->is_active);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.api-tokens.destroy', [$account, $apiToken]))
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']))
            ->assertSessionHas('status', __('app.api_token_revoked'));

        $this->assertFalse($apiToken->refresh()->is_active);
    }

    public function test_manager_without_studio_settings_permission_cannot_create_api_token(): void
    {
        $manager = User::factory()->create();
        $account = Account::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($manager, 'user')
            ->create(['role' => AccountRole::Manager->value, 'permissions' => null]);

        $this->actingAs($manager)
            ->post(route('dashboard.accounts.api-tokens.store', $account), [
                'name' => 'Website form',
            ])
            ->assertForbidden();

        $this->assertFalse(AccountApiToken::whereBelongsTo($account)->exists());
    }
}
