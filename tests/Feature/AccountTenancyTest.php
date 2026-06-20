<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountTenancyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_normal_user_cannot_create_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard.accounts.create'))
            ->assertForbidden();

        $this->actingAs($user)->post('/dashboard/accounts', [
            'name' => 'Studio Test',
            'default_language' => 'uk',
            'default_currency' => 'UAH',
            'timezone' => 'Europe/Kyiv',
        ])->assertForbidden();

        $this->assertFalse(Account::where('slug', 'studio-test')->exists());
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

    public function test_studio_owner_is_redirected_to_their_single_studio_workspace(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.index'))
            ->assertRedirect(route('dashboard.accounts.show', $account));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.index'))
            ->assertRedirect(route('dashboard.accounts.show', $account));
    }

    public function test_studio_owner_can_upload_studio_logo(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), [
                'name' => $account->name,
                'slug' => $account->slug,
                'default_language' => 'uk',
                'default_currency' => 'UAH',
                'brand_color' => '#3B223F',
                'timezone' => 'Europe/Kyiv',
                'logo' => UploadedFile::fake()->image('studio-logo.png', 256, 256),
            ])
            ->assertRedirect(route('dashboard.accounts.brand.edit', $account));

        $account->refresh();

        $this->assertNotNull($account->logo_path);
        Storage::disk('public')->assertExists($account->logo_path);
    }

    public function test_studio_owner_can_update_own_account_profile_from_account_edit(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create([
            'name' => 'Old Owner',
            'email' => 'old-owner@example.com',
            'phone' => null,
        ]);
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.owner-profile.edit', $account))
            ->assertOk()
            ->assertSee('Мій акаунт')
            ->assertSee('Мій бренд')
            ->assertSee('old-owner@example.com');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.owner-profile.update', $account), [
                'name' => 'Настя Owner',
                'email' => 'nastya-owner-updated@example.com',
                'phone' => '+380501234567',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
                'avatar' => UploadedFile::fake()->image('avatar.png', 256, 256),
            ])
            ->assertRedirect(route('dashboard.accounts.owner-profile.edit', $account));

        $owner->refresh();

        $this->assertSame('Настя Owner', $owner->name);
        $this->assertSame('nastya-owner-updated@example.com', $owner->email);
        $this->assertSame('+380501234567', $owner->phone);
        $this->assertTrue(Hash::check('new-password', $owner->password));
        $this->assertNotNull($owner->avatar_path);
        Storage::disk('public')->assertExists($owner->avatar_path);
    }

    public function test_studio_owner_sidebar_prioritizes_daily_studio_work(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSeeInOrder([
                'Моя студія',
                'Актуальне',
                'Заняття',
                'Клієнти',
                'Тижневий розклад',
                'Налаштування студії',
                'Локації',
                'Зали',
                'Напрями',
                'Формати занять',
                'Абонементи',
                'Тренери',
                'Рівні тренерів',
                'Інтеграції',
                'Налаштування акаунта',
                'Мій бренд',
                'Мій акаунт',
            ])
            ->assertDontSee('Брендінг')
            ->assertDontSee('Шаблон тижня')
            ->assertDontSee('Мій бізнес')
            ->assertDontSee('Мій аккаунт')
            ->assertSee(route('dashboard.accounts.brand.edit', $account), false)
            ->assertSee(route('dashboard.accounts.owner-profile.edit', $account), false)
            ->assertDontSee('tab=business', false)
            ->assertDontSee('tab=account', false);
    }

    public function test_legacy_account_edit_route_redirects_to_separate_pages(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.edit', [$account, 'tab' => 'business']))
            ->assertRedirect(route('dashboard.accounts.brand.edit', $account));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.edit', [$account, 'tab' => 'account']))
            ->assertRedirect(route('dashboard.accounts.owner-profile.edit', $account));
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
