<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EventFestivalStaffManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_update_and_remove_event_festival_staff(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.event-festival-staff.create', $account))
            ->assertOk()
            ->assertSee('name="password_confirmation"', false);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.event-festival-staff.store', $account), [
                'name' => 'Door Operator',
                'email' => 'door.operator@example.test',
                'password' => 'initial-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.event-festival-staff.store', $account), [
                'name' => '  Door Operator  ',
                'email' => '  DOOR.OPERATOR@example.test  ',
                'password' => 'initial-password',
                'password_confirmation' => 'initial-password',
            ])
            ->assertRedirect(route('dashboard.accounts.event-festival-staff.index', $account));

        $user = User::query()->where('email', 'door.operator@example.test')->firstOrFail();
        $membership = $account->memberships()->whereBelongsTo($user)->firstOrFail();

        $this->assertSame('Door Operator', $user->name);
        $this->assertTrue(Hash::check('initial-password', $user->password));
        $this->assertSame(AccountRole::EventFestivalStaff, $membership->role);
        $this->assertSame([], $membership->permissions);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.event-festival-staff.edit', [$account, $membership]))
            ->assertOk()
            ->assertSee('name="password_confirmation"', false);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.event-festival-staff.update', [$account, $membership]), [
                'name' => 'Updated Operator',
                'email' => 'updated.operator@example.test',
                'password' => 'replacement-password',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('initial-password', $user->refresh()->password));

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.event-festival-staff.update', [$account, $membership]), [
                'name' => 'Updated Operator',
                'email' => 'updated.operator@example.test',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('dashboard.accounts.event-festival-staff.index', $account));

        $this->assertSame('Updated Operator', $user->refresh()->name);
        $this->assertSame('updated.operator@example.test', $user->email);
        $this->assertTrue(Hash::check('initial-password', $user->password));

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.event-festival-staff.update', [$account, $membership]), [
                'name' => 'Updated Operator',
                'email' => 'updated.operator@example.test',
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-password',
            ])
            ->assertRedirect(route('dashboard.accounts.event-festival-staff.index', $account));

        $this->assertTrue(Hash::check('replacement-password', $user->refresh()->password));

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.event-festival-staff.destroy', [$account, $membership]))
            ->assertRedirect(route('dashboard.accounts.event-festival-staff.index', $account));

        $this->assertModelMissing($membership);
        $this->assertModelExists($user);
    }

    public function test_creation_rejects_an_email_used_by_any_existing_user(): void
    {
        $owner = User::factory()->create();
        $existingUser = User::factory()->create(['email' => 'existing@example.test']);
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.event-festival-staff.store', $account), [
                'name' => 'Existing Person',
                'email' => 'EXISTING@example.test',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertFalse($account->memberships()->whereBelongsTo($existingUser)->exists());
        $this->assertSame(1, User::query()->where('email', 'existing@example.test')->count());
    }

    public function test_only_platform_owner_studio_owner_and_studio_admin_can_manage_staff(): void
    {
        $account = Account::factory()->create();
        $admin = User::factory()->create();
        $manager = User::factory()->create();
        $staff = User::factory()->create();

        $account->memberships()->createMany([
            ['user_id' => $admin->id, 'role' => AccountRole::Admin->value],
            ['user_id' => $manager->id, 'role' => AccountRole::Manager->value],
            ['user_id' => $staff->id, 'role' => AccountRole::EventFestivalStaff->value, 'permissions' => []],
        ]);

        $payload = [
            'name' => 'New Operator',
            'email' => 'new.operator@example.test',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ];

        $this->actingAs($manager)
            ->post(route('dashboard.accounts.event-festival-staff.store', $account), $payload)
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('dashboard.accounts.event-festival-staff.store', $account), $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('dashboard.accounts.event-festival-staff.store', $account), $payload)
            ->assertRedirect(route('dashboard.accounts.event-festival-staff.index', $account));
    }

    public function test_staff_membership_routes_reject_other_accounts_and_other_roles(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $otherAccount->addOwner($owner);

        $foreignStaff = AccountMembership::factory()
            ->for($otherAccount)
            ->for(User::factory(), 'user')
            ->create(['role' => AccountRole::EventFestivalStaff->value, 'permissions' => []]);
        $ownerMembership = $account->membershipFor($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.event-festival-staff.edit', [$account, $foreignStaff]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.event-festival-staff.edit', [$account, $ownerMembership]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.event-festival-staff.update', [$account, $ownerMembership]), [])
            ->assertNotFound();
    }

    public function test_owner_can_filter_the_separate_staff_list(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $visible = User::factory()->create(['name' => 'Front Door', 'email' => 'front@example.test']);
        $hidden = User::factory()->create(['name' => 'Back Office', 'email' => 'back@example.test']);
        $account->memberships()->createMany([
            ['user_id' => $visible->id, 'role' => AccountRole::EventFestivalStaff->value, 'permissions' => []],
            ['user_id' => $hidden->id, 'role' => AccountRole::EventFestivalStaff->value, 'permissions' => []],
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.event-festival-staff.index', [$account, 'q' => 'front']))
            ->assertOk()
            ->assertSee('Front Door')
            ->assertDontSee('Back Office');
    }
}
