<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
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

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.trainers.store', $account), [
                'name' => 'Катя',
                'email' => 'katya.profile@example.com',
                'phone' => '+380501112233',
                'bio' => 'Trainer profile.',
                'photo' => UploadedFile::fake()->image('katya.png'),
                'is_active' => '1',
                'create_login' => '1',
                'user_email' => 'katya.login@example.com',
                'user_password' => 'password',
                'role' => AccountRole::Trainer->value,
                'permissions' => [
                    StudioPermission::ManageSchedule->value,
                    StudioPermission::MarkAttendance->value,
                ],
            ])
            ->assertRedirect(route('dashboard.accounts.trainers.index', $account));

        $trainer = $account->trainers()->where('email', 'katya.profile@example.com')->firstOrFail();
        $loginUser = User::where('email', 'katya.login@example.com')->firstOrFail();

        $this->assertSame($loginUser->id, $trainer->user_id);
        Storage::disk('public')->assertExists($trainer->photo_path);
        $this->assertTrue($account->memberships()
            ->whereBelongsTo($loginUser)
            ->where('role', AccountRole::Trainer->value)
            ->exists());
        $this->assertTrue($account->userCan($loginUser, StudioPermission::MarkAttendance));
    }
}
