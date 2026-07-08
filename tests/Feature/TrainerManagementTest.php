<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\Location;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrainerManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_trainer_with_photo_and_optional_login(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $trainerType = TrainerType::factory()->for($account)->default()->create();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.trainers.store', $account), [
                'name' => 'Катя',
                'trainer_type_id' => $trainerType->id,
                'email' => 'katya.profile@example.com',
                'phone' => '+380501112233',
                'bio' => 'Trainer profile.',
                'photo' => UploadedFile::fake()->image('katya.png'),
                'is_active' => '1',
                'create_login' => '1',
                'user_email' => 'katya.login@example.com',
                'user_password' => 'password',
                'permissions' => [
                    StudioPermission::ManageSchedule->value,
                    StudioPermission::MarkAttendance->value,
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.trainers.index', $account));

        $trainer = $account->trainers()->where('email', 'katya.profile@example.com')->firstOrFail();
        $loginUser = User::where('email', 'katya.login@example.com')->firstOrFail();

        $this->assertSame($loginUser->id, $trainer->user_id);
        $this->assertSame($trainerType->id, $trainer->trainer_type_id);
        Storage::disk('public')->assertExists($trainer->photo_path);
        $this->assertTrue($account->memberships()
            ->whereBelongsTo($loginUser)
            ->where('role', AccountRole::Trainer->value)
            ->exists());
        $this->assertTrue($account->userCan($loginUser, StudioPermission::MarkAttendance));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.trainers.index', $account))
            ->assertOk()
            ->assertSee($trainerType->name);
    }

    public function test_owner_can_sync_trainer_locations_on_create_and_update(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        $firstLocation = Location::factory()->for($account)->create(['name' => 'Center']);
        $secondLocation = Location::factory()->for($account)->create(['name' => 'Podil']);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.trainers.store', $account), [
                'name' => 'Олена',
                'trainer_type_id' => $trainerType->id,
                'email' => 'olena.profile@example.com',
                'phone' => '+380501112244',
                'bio' => 'Trainer profile.',
                'is_active' => '1',
                'create_login' => '0',
                'location_ids' => [$firstLocation->id],
            ])
            ->assertRedirect(route('dashboard.accounts.trainers.index', $account));

        $trainer = $account->trainers()->where('email', 'olena.profile@example.com')->firstOrFail();

        $this->assertSame([$firstLocation->id], $trainer->locations()->pluck('locations.id')->all());

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.trainers.update', [$account, $trainer]), [
                'name' => 'Олена',
                'trainer_type_id' => $trainerType->id,
                'email' => 'olena.profile@example.com',
                'phone' => '+380501112244',
                'bio' => 'Trainer profile.',
                'is_active' => '1',
                'create_login' => '0',
                'location_ids' => [$secondLocation->id],
            ])
            ->assertRedirect(route('dashboard.accounts.trainers.index', $account));

        $this->assertSame([$secondLocation->id], $trainer->fresh()->locations()->pluck('locations.id')->all());

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.trainers.update', [$account, $trainer]), [
                'name' => 'Олена',
                'trainer_type_id' => $trainerType->id,
                'email' => 'olena.profile@example.com',
                'phone' => '+380501112244',
                'bio' => 'Trainer profile.',
                'is_active' => '1',
                'create_login' => '0',
                'location_ids' => [],
            ])
            ->assertRedirect(route('dashboard.accounts.trainers.index', $account));

        $this->assertSame([], $trainer->fresh()->locations()->pluck('locations.id')->all());
    }

    public function test_linked_trainer_can_open_self_timeframes_only_when_feature_is_enabled(): void
    {
        $trainerUser = User::factory()->create();
        $account = Account::factory()->create(['trainer_private_timeframes_enabled' => true]);
        $trainerType = TrainerType::factory()->for($account)->default()->create();
        Location::factory()->for($account)->create();
        $trainer = $account->trainers()->create([
            'user_id' => $trainerUser->id,
            'trainer_type_id' => $trainerType->id,
            'name' => 'Ірина',
            'slug' => 'iryna',
            'is_active' => true,
        ]);
        $account->users()->attach($trainerUser, [
            'role' => AccountRole::Trainer->value,
        ]);

        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.trainer-private-timeframes.mine', $account))
            ->assertOk()
            ->assertSee(__('app.trainer_private_timeframes'))
            ->assertSee($trainer->name);

        $account->update(['trainer_private_timeframes_enabled' => false]);

        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.trainer-private-timeframes.mine', $account))
            ->assertNotFound();
    }
}
