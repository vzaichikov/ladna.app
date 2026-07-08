<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Location;
use App\Models\TelegramBotInstallation;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
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

        $this->actingAs($user)->post(route('dashboard.accounts.store', absolute: false), [
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
        $assetDirectory = public_path($account->slug.'/pwa');

        $this->beforeApplicationDestroyed(function () use ($account): void {
            File::deleteDirectory(public_path($account->slug.'/pwa'));
            @rmdir(public_path($account->slug));
        });

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), [
                'name' => $account->name,
                'slug' => $account->slug,
                'default_language' => 'uk',
                'default_currency' => 'UAH',
                'brand_color' => '#3B223F',
                'timezone' => 'Europe/Kyiv',
                'logo' => UploadedFile::fake()->image('studio-logo.png', 512, 512),
            ])
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', $account));

        $account->refresh();

        $this->assertNotNull($account->logo_path);
        Storage::disk('public')->assertExists($account->logo_path);
        $this->assertFileExists($assetDirectory.'/icon-192.png');
        $this->assertFileExists($assetDirectory.'/icon-512.png');
        $this->assertFileExists($assetDirectory.'/screenshot-wide.png');
        $this->assertFileExists($assetDirectory.'/screenshot-narrow.png');
    }

    public function test_studio_logo_upload_requires_png_at_least_512_pixels(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $payload = [
            'name' => $account->name,
            'slug' => $account->slug,
            'default_language' => 'uk',
            'default_currency' => 'UAH',
            'brand_color' => '#3B223F',
            'timezone' => 'Europe/Kyiv',
        ];

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), [
                ...$payload,
                'logo' => UploadedFile::fake()->image('studio-logo.jpg', 512, 512),
            ])
            ->assertSessionHasErrors('logo');

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), [
                ...$payload,
                'logo' => UploadedFile::fake()->image('studio-logo.png', 511, 512),
            ])
            ->assertSessionHasErrors('logo');

        $this->assertNull($account->fresh()->logo_path);
    }

    public function test_studio_owner_cannot_update_account_to_reserved_public_slug(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['slug' => 'reserved-owner-studio']);
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), [
                'name' => $account->name,
                'slug' => 'app',
                'default_language' => 'uk',
                'default_currency' => 'UAH',
                'brand_color' => '#3B223F',
                'timezone' => 'Europe/Kyiv',
            ])
            ->assertSessionHasErrors('slug');

        $this->assertSame('reserved-owner-studio', $account->fresh()->slug);
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
            ->assertSee('Загальні налаштування')
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
        $this->platformOwnerTelegramBot(['is_enabled' => false]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSeeInOrder([
                'Моя студія',
                'Актуальне',
                'Заняття',
                'Клієнти',
                'Посилання',
                'Лендінг студії',
                'публічна сторінка студії',
                'Налаштування студії',
                'Локації',
                'Зали',
                'Напрями',
                'Групові заняття',
                'Індивідуальні заняття',
                'Оренда',
                'Тижневий розклад',
                'Абонементи і ціни',
                'Тренери',
                'Рівні тренерів',
                'Інтеграції',
                'Загальні налаштування',
                'Налаштування акаунта',
                'Мій акаунт',
                'Тариф та платежі',
                'Журнал дій',
            ])
            ->assertSee('працює на Ladna')
            ->assertSee(route('public.studio', $account->slug), false)
            ->assertSee('target="_blank"', false)
            ->assertDontSee('TG-бот підтримки')
            ->assertDontSee('Мій бренд')
            ->assertDontSee('Брендінг')
            ->assertDontSee('Шаблон тижня')
            ->assertDontSee('Мій бізнес')
            ->assertDontSee('Мій аккаунт')
            ->assertSee(route('dashboard.accounts.general-settings.edit', $account), false)
            ->assertSee(route('dashboard.accounts.owner-profile.edit', $account), false)
            ->assertSee(route('dashboard.accounts.tariff-payments.show', $account), false)
            ->assertSee(route('dashboard.accounts.activity-logs.index', $account), false)
            ->assertDontSee('tab=business', false)
            ->assertDontSee('tab=account', false);
    }

    public function test_studio_owner_sidebar_links_to_enabled_platform_owner_telegram_bot(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->platformOwnerTelegramBot([
            'bot_username' => '@ladna_owner_bot',
            'is_enabled' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee('TG-бот підтримки')
            ->assertSee('підпишись на сповіщення')
            ->assertSee('href="https://t.me/ladna_owner_bot"', false)
            ->assertSee('target="_blank"', false);
    }

    public function test_studio_owner_sidebar_hides_disabled_platform_owner_telegram_bot(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->platformOwnerTelegramBot([
            'bot_username' => 'disabled_owner_bot',
            'is_enabled' => false,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee('Лендінг студії')
            ->assertDontSee('TG-бот підтримки')
            ->assertDontSee('disabled_owner_bot');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function platformOwnerTelegramBot(array $attributes): TelegramBotInstallation
    {
        $installation = TelegramBotInstallation::query()
            ->where('scope_type', 'platform')
            ->where('scope_id', 0)
            ->where('profile', 'owner')
            ->first();

        if (! $installation) {
            return TelegramBotInstallation::factory()->platformOwner()->create($attributes);
        }

        $installation->forceFill(array_merge([
            'account_id' => null,
            'scope_type' => 'platform',
            'scope_id' => 0,
            'profile' => 'owner',
            'status' => 'configured',
        ], $attributes))->save();

        return $installation->refresh();
    }

    public function test_trainer_sidebar_only_shows_authorized_items_and_header_trainer_level(): void
    {
        $trainerUser = User::factory()->create(['name' => 'Trainer User']);
        $account = Account::factory()->create();
        $trainerType = TrainerType::factory()->for($account)->create(['name' => 'ТОП-тренер']);
        $account->users()->attach($trainerUser->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => null,
        ]);
        Trainer::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->for($trainerType, 'trainerType')
            ->create(['name' => 'Trainer User']);

        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.scheduled-classes.index', $account))
            ->assertOk()
            ->assertSee('ТОП-тренер')
            ->assertDontSee('Власник студії')
            ->assertSee(route('dashboard.accounts.show', $account), false)
            ->assertSee(route('dashboard.accounts.scheduled-classes.index', $account), false)
            ->assertSee(route('dashboard.accounts.schedule-series.index', $account), false)
            ->assertDontSee('href="'.route('dashboard.accounts.customers.index', $account).'"', false)
            ->assertDontSee(route('dashboard.accounts.customer-class-passes.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.activity-logs.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.locations.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.rooms.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.activity-directions.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.class-types.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.group-classes.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.private-lessons.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.room-rentals.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.class-pass-plans.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.trainers.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.trainer-types.index', $account), false)
            ->assertDontSee(route('dashboard.accounts.tariff-payments.show', $account), false)
            ->assertDontSee(route('dashboard.accounts.integrations.index', $account), false);
    }

    public function test_legacy_account_edit_route_redirects_to_separate_pages(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.edit', [$account, 'tab' => 'business']))
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', $account));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.edit', [$account, 'tab' => 'account']))
            ->assertRedirect(route('dashboard.accounts.owner-profile.edit', $account));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.brand.edit', [$account, 'tab' => 'api']))
            ->assertRedirect(route('dashboard.accounts.general-settings.edit', [$account, 'tab' => 'api']));
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
