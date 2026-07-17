<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\ScheduleSeries;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_database_seeder_creates_only_the_configured_platform_owner(): void
    {
        $this->configurePlatformBootstrap();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->seed(DatabaseSeeder::class);

        $platformOwner = User::query()->sole();

        $this->assertSame('Test Platform Owner', $platformOwner->name);
        $this->assertSame('platform-owner@example.test', $platformOwner->email);
        $this->assertSame(SystemRole::PlatformAdmin, $platformOwner->system_role);
        $this->assertTrue($platformOwner->email_verified_at->isToday());
        $this->assertTrue(Hash::check('local-platform-password', $platformOwner->password));
        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, SubscriptionPlan::query()->count());
        $this->assertSame(0, ClassPassPlan::query()->count());
        $this->assertSame(0, ScheduleSeries::query()->count());
        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $query): bool => preg_match('/\b(?:delete|truncate)\b/i', $query) === 1,
        )));
    }

    public function test_database_seeder_requires_explicit_opt_in(): void
    {
        $this->configurePlatformBootstrap(enabled: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LADNA_PLATFORM_BOOTSTRAP_ENABLED=true');

        $this->seed(DatabaseSeeder::class);
    }

    public function test_database_seeder_requires_platform_owner_credentials(): void
    {
        config([
            'bootstrap.enabled' => true,
            'bootstrap.platform_owner.name' => null,
            'bootstrap.platform_owner.email' => null,
            'bootstrap.platform_owner.password' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Platform owner name must be configured');

        $this->seed(DatabaseSeeder::class);
    }

    public function test_database_seeder_refuses_a_nonempty_application_database(): void
    {
        $this->configurePlatformBootstrap();
        $existingUser = User::factory()->create();

        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('The seeder accepted a nonempty application database.');
        } catch (RuntimeException $exception) {
            $this->assertSame('DatabaseSeeder requires an empty application database.', $exception->getMessage());
        }

        $this->assertModelExists($existingUser);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Account::query()->count());
    }

    public function test_database_seeder_refuses_an_existing_studio_without_users(): void
    {
        $this->configurePlatformBootstrap();
        $existingAccount = Account::factory()->create();

        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('The seeder accepted an existing studio account.');
        } catch (RuntimeException $exception) {
            $this->assertSame('DatabaseSeeder requires an empty application database.', $exception->getMessage());
        }

        $this->assertModelExists($existingAccount);
        $this->assertSame(1, Account::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_database_seeder_refuses_existing_platform_business_data(): void
    {
        $this->configurePlatformBootstrap();
        $plan = SubscriptionPlan::factory()->create();

        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('The seeder accepted existing platform business data.');
        } catch (RuntimeException $exception) {
            $this->assertSame('DatabaseSeeder requires an empty application database.', $exception->getMessage());
        }

        $this->assertModelExists($plan);
        $this->assertSame(0, User::query()->count());
    }

    public function test_database_seeder_is_disabled_in_production(): void
    {
        $this->configurePlatformBootstrap();
        $originalEnvironment = app()->environment();

        app()->detectEnvironment(fn (): string => 'production');

        try {
            (new DatabaseSeeder)->run();
            $this->fail('The seeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertSame('DatabaseSeeder is disabled in production.', $exception->getMessage());
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
        }

        $this->assertSame(0, User::query()->count());
    }

    private function configurePlatformBootstrap(bool $enabled = true): void
    {
        config([
            'bootstrap.enabled' => $enabled,
            'bootstrap.platform_owner.name' => 'Test Platform Owner',
            'bootstrap.platform_owner.email' => 'platform-owner@example.test',
            'bootstrap.platform_owner.password' => 'local-platform-password',
        ]);
    }
}
