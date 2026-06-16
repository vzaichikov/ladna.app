<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountTenancyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_create_account_and_becomes_owner(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/dashboard/accounts', [
            'name' => 'Studio Test',
            'default_language' => 'uk',
            'default_currency' => 'UAH',
            'timezone' => 'Europe/Kyiv',
        ]);

        $account = Account::where('slug', 'studio-test')->firstOrFail();

        $response->assertRedirect(route('dashboard.accounts.show', $account));
        $this->assertTrue($account->isAccessibleBy($user));
        $this->assertTrue($account->memberships()
            ->whereBelongsTo($user)
            ->where('role', AccountRole::Owner->value)
            ->exists());
    }

    public function test_internal_user_cannot_view_unrelated_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();

        $account->addOwner($user);
        $otherAccount->addOwner($otherUser);

        $this->actingAs($user)
            ->get(route('dashboard.accounts.show', $otherAccount))
            ->assertForbidden();
    }

    public function test_locations_are_scoped_to_their_parent_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $otherLocation = Location::factory()->for($otherAccount)->create();

        $account->addOwner($user);
        $otherAccount->addOwner($otherUser);

        $this->actingAs($user)
            ->post(route('dashboard.accounts.locations.store', $account), [
                'name' => 'Main Room',
                'default_language' => 'uk',
                'slug' => 'main-room',
                'timezone' => 'Europe/Kyiv',
                'is_active' => '1',
            ])
            ->assertRedirect(route('dashboard.accounts.locations.index', $account));

        $this->assertTrue($account->locations()->where('slug', 'main-room')->exists());

        $this->actingAs($user)
            ->get(route('dashboard.accounts.locations.edit', [$account, $otherLocation]))
            ->assertNotFound();
    }
}
