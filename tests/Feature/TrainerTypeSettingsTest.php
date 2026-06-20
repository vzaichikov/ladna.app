<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TrainerTypeSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_with_studio_settings_permission_can_manage_trainer_types(): void
    {
        $manager = User::factory()->create();
        $account = Account::factory()->create();
        $account->users()->attach($manager->id, [
            'role' => AccountRole::Manager->value,
            'permissions' => [StudioPermission::ManageStudioSettings->value],
        ]);
        $defaultType = TrainerType::factory()->for($account)->default()->create();

        $this->actingAs($manager)
            ->get(route('dashboard.accounts.trainer-types.index', $account))
            ->assertOk()
            ->assertSee(__('app.trainer_types'))
            ->assertDontSee('Брендінг')
            ->assertSee($defaultType->name);

        $this->actingAs($manager)
            ->post(route('dashboard.accounts.trainer-types.store', $account), [
                'name' => 'TOP-trainer',
                'icon' => 'crown',
                'color' => '#D80A7D',
                'is_default' => '1',
                'sort_order' => 20,
            ])
            ->assertRedirect(route('dashboard.accounts.trainer-types.index', $account));

        $topType = $account->trainerTypes()->where('name', 'TOP-trainer')->firstOrFail();

        $this->assertTrue($topType->is_default);
        $this->assertFalse($defaultType->fresh()->is_default);

        $this->actingAs($manager)
            ->put(route('dashboard.accounts.trainer-types.update', [$account, $topType]), [
                'name' => 'Senior trainer',
                'icon' => 'star',
                'color' => '#C7F000',
                'is_default' => '0',
                'sort_order' => 30,
            ])
            ->assertRedirect(route('dashboard.accounts.trainer-types.index', $account));

        $this->assertSame('Senior trainer', $topType->fresh()->name);
        $this->assertTrue($topType->fresh()->is_default);
    }

    public function test_old_studio_settings_route_redirects_to_trainer_levels(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.studio-settings.index', $account))
            ->assertRedirect(route('dashboard.accounts.trainer-types.index', $account));
    }

    public function test_default_or_only_trainer_type_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $defaultType = TrainerType::factory()->for($account)->default()->create();

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.trainer-types.destroy', [$account, $defaultType]))
            ->assertSessionHasErrors('trainer_type');

        $this->assertModelExists($defaultType);
    }

    public function test_deleting_non_default_type_reassigns_trainers_and_preserves_plan_coverage(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $defaultType = TrainerType::factory()->for($account)->default()->create();
        $topType = TrainerType::factory()->for($account)->create(['name' => 'TOP-trainer']);
        $trainer = Trainer::factory()->for($account)->create(['trainer_type_id' => $topType->id]);
        $classPassPlan = ClassPassPlan::factory()->for($account)->create();
        $classPassPlan->trainerTypes()->sync([$topType->id]);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.trainer-types.destroy', [$account, $topType]))
            ->assertRedirect(route('dashboard.accounts.trainer-types.index', $account));

        $this->assertModelMissing($topType);
        $this->assertSame($defaultType->id, $trainer->fresh()->trainer_type_id);
        $this->assertTrue($classPassPlan->trainerTypes()->whereKey($defaultType->id)->exists());
    }

    public function test_trainer_types_are_scoped_to_the_selected_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $otherType = TrainerType::factory()->for($otherAccount)->default()->create();

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.trainer-types.update', [$account, $otherType]), [
                'name' => 'Wrong account',
                'icon' => 'star',
                'color' => '#3B223F',
                'is_default' => '1',
                'sort_order' => 10,
            ])
            ->assertNotFound();
    }
}
