<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->ensureBootstrapIsAllowed();

        $platformOwner = $this->platformOwnerCredentials();

        DB::transaction(fn (): User => User::create([
            'name' => $platformOwner['name'],
            'email' => $platformOwner['email'],
            'password' => $platformOwner['password'],
            'system_role' => SystemRole::PlatformAdmin->value,
            'email_verified_at' => now(),
        ]));
    }

    private function ensureBootstrapIsAllowed(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DatabaseSeeder is disabled in production.');
        }

        if (config('bootstrap.enabled') !== true) {
            throw new RuntimeException('DatabaseSeeder requires LADNA_PLATFORM_BOOTSTRAP_ENABLED=true.');
        }

        $infrastructureTables = [
            'cache',
            'cache_locks',
            'failed_jobs',
            'job_batches',
            'jobs',
            'migrations',
            'password_reset_tokens',
            'scheduled_task_statuses',
            'sessions',
            'sqlite_sequence',
        ];

        foreach (Schema::getTableListing(DB::getDatabaseName()) as $table) {
            $tableName = str_contains($table, '.') ? str($table)->afterLast('.')->toString() : $table;

            if (! in_array($tableName, $infrastructureTables, true) && DB::table($table)->exists()) {
                throw new RuntimeException('DatabaseSeeder requires an empty application database.');
            }
        }
    }

    /**
     * @return array{name: string, email: string, password: string}
     */
    private function platformOwnerCredentials(): array
    {
        $platformOwner = config('bootstrap.platform_owner');

        if (! is_array($platformOwner)) {
            throw new RuntimeException('Platform owner credentials are not configured.');
        }

        foreach (['name', 'email', 'password'] as $field) {
            if (! is_string($platformOwner[$field] ?? null) || blank($platformOwner[$field])) {
                throw new RuntimeException("Platform owner {$field} must be configured in the environment.");
            }
        }

        if (! filter_var($platformOwner['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Platform owner email must be a valid email address.');
        }

        return $platformOwner;
    }
}
