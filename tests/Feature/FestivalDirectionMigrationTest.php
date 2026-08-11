<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FestivalDirectionMigrationTest extends TestCase
{
    public function test_it_migrates_directions_and_category_links_before_removing_classifications(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();

            $this->expandMigration()->up();
            $this->backfillMigration()->up();

            $directions = DB::table('festival_directions')
                ->orderBy('sort_order')
                ->get();

            $this->assertCount(3, $directions);
            $this->assertSame(['stage', 'backstage', 'STAGE-201'], $directions->pluck('code')->all());
            $this->assertSame(['Direction B', 'Unused direction', 'Direction A'], $directions->pluck('name')->all());
            $this->assertSame([10, 20, 30], $directions->pluck('sort_order')->all());
            $this->assertSame([1, 0, 1], $directions->pluck('is_active')->map(fn (mixed $active): int => (int) $active)->all());

            $categoryDirections = DB::table('festival_categories as categories')
                ->join('festival_directions as directions', 'directions.id', '=', 'categories.festival_direction_id')
                ->orderBy('categories.id')
                ->pluck('directions.name')
                ->all();

            $this->assertSame(['Direction A', 'Direction B'], $categoryDirections);
            $this->assertNull(DB::table('festival_categories')->where('id', 301)->value('requirements_html'));

            $this->contractMigration()->up();

            $this->assertTrue(Schema::hasTable('festival_directions'));
            $this->assertFalse(Schema::hasTable('festival_category_option'));
            $this->assertFalse(Schema::hasTable('festival_classification_options'));
            $this->assertFalse(Schema::hasTable('festival_classification_axes'));
            $this->assertFalse(Schema::hasColumn('festival_categories', 'workflow'));
            $this->assertFalse(Schema::hasColumn('festival_categories', 'rule_snapshot'));
            $this->assertTrue(Schema::hasColumn('festival_categories', 'requirements_html'));

            $directionColumn = collect(Schema::getColumns('festival_categories'))
                ->firstWhere('name', 'festival_direction_id');

            $this->assertIsArray($directionColumn);
            $this->assertFalse($directionColumn['nullable']);

            try {
                DB::table('festival_categories')->where('id', 301)->update(['festival_direction_id' => null]);
                $this->fail('The required category direction accepted a null value.');
            } catch (QueryException) {
                $this->assertNotNull(DB::table('festival_categories')->where('id', 301)->value('festival_direction_id'));
            }
        });
    }

    public function test_backfill_aborts_when_a_category_has_no_direction_without_removing_legacy_data(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();
            DB::table('festival_category_option')
                ->where('festival_category_id', 301)
                ->where('festival_classification_option_id', 201)
                ->delete();
            $this->expandMigration()->up();

            try {
                $this->backfillMigration()->up();
                $this->fail('The backfill accepted a category without a direction.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('exactly one direction', $exception->getMessage());
            }

            $this->assertSame(0, DB::table('festival_directions')->count());
            $this->assertTrue(Schema::hasTable('festival_category_option'));
            $this->assertTrue(Schema::hasTable('festival_classification_options'));
            $this->assertTrue(Schema::hasTable('festival_classification_axes'));
        });
    }

    public function test_backfill_aborts_when_a_category_has_several_directions(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();
            DB::table('festival_category_option')->insert([
                'id' => 404,
                'account_id' => 1,
                'festival_category_id' => 301,
                'festival_classification_option_id' => 202,
            ]);
            $this->expandMigration()->up();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('exactly one direction');

            $this->backfillMigration()->up();
        });
    }

    public function test_backfill_aborts_when_legacy_rows_do_not_belong_to_the_edition_account(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();
            DB::table('accounts')->insert(['id' => 2]);
            DB::table('festival_categories')->where('id', 301)->update(['account_id' => 2]);
            DB::table('festival_classification_axes')->where('id', 101)->update(['account_id' => 2]);
            DB::table('festival_classification_options')->where('id', 201)->update(['account_id' => 2]);
            DB::table('festival_category_option')->where('festival_category_id', 301)->update(['account_id' => 2]);
            $this->expandMigration()->up();

            try {
                $this->backfillMigration()->up();
                $this->fail('The backfill accepted records owned by a different account than their edition.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('does not own its edition', $exception->getMessage());
            }

            $this->assertSame(0, DB::table('festival_directions')->count());
            $this->assertTrue(Schema::hasTable('festival_classification_axes'));
        });
    }

    public function test_contract_aborts_before_cleanup_if_migrated_category_direction_is_invalid(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();
            $this->expandMigration()->up();
            $this->backfillMigration()->up();
            DB::table('festival_categories')->where('id', 301)->update(['festival_direction_id' => null]);

            try {
                $this->contractMigration()->up();
                $this->fail('The contract migration removed legacy data despite an invalid migrated direction.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('no valid account- and edition-scoped direction', $exception->getMessage());
            }

            $this->assertTrue(Schema::hasTable('festival_category_option'));
            $this->assertTrue(Schema::hasTable('festival_classification_options'));
            $this->assertTrue(Schema::hasTable('festival_classification_axes'));
            $this->assertTrue(Schema::hasColumn('festival_categories', 'workflow'));
            $this->assertTrue(Schema::hasColumn('festival_categories', 'rule_snapshot'));
        });
    }

    private function withIsolatedMigrationDatabase(callable $callback): void
    {
        $originalConnection = config('database.default');
        $connection = 'festival_direction_migration_testing';
        $originalConfig = config("database.connections.{$originalConnection}");
        $database = (string) ($originalConfig['database'] ?? '');

        if (($originalConfig['driver'] ?? null) !== 'mysql' || ! str_ends_with($database, '_testing')) {
            $this->markTestSkipped('Direction migration tests require the explicitly dedicated MySQL test database.');
        }

        $prefix = 'fdm_'.bin2hex(random_bytes(4)).'_';

        config([
            "database.connections.{$connection}" => [
                ...$originalConfig,
                'prefix' => $prefix,
                'prefix_indexes' => true,
            ],
            'database.default' => $connection,
        ]);

        DB::purge($connection);

        try {
            DB::connection($connection)->getPdo();
            $this->createLegacySchema();
            $callback();
        } finally {
            $this->dropIsolatedMigrationTables();
            DB::disconnect($connection);
            DB::purge($connection);
            config(['database.default' => $originalConnection]);
        }
    }

    private function dropIsolatedMigrationTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'festival_category_option',
            'festival_categories',
            'festival_classification_options',
            'festival_classification_axes',
            'festival_directions',
            'festival_editions',
            'accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    private function createLegacySchema(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('festival_editions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
        });

        Schema::create('festival_classification_axes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_edition_id');
            $table->string('code');
            $table->string('name');
            $table->string('kind')->default('custom');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['festival_edition_id', 'code'], 'legacy_axis_code_unique');
        });

        Schema::create('festival_classification_options', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_edition_id');
            $table->unsignedBigInteger('festival_classification_axis_id');
            $table->string('code');
            $table->string('label');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['festival_classification_axis_id', 'code'], 'festival_axis_option_code_unique');
        });

        Schema::create('festival_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_edition_id');
            $table->unsignedBigInteger('festival_workflow_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('workflow')->default('review');
            $table->unsignedSmallInteger('min_members')->default(1);
            $table->unsignedSmallInteger('max_members')->default(1);
            $table->unsignedSmallInteger('min_age')->nullable();
            $table->unsignedSmallInteger('max_age')->nullable();
            $table->unsignedInteger('min_duration_seconds')->nullable();
            $table->unsignedInteger('max_duration_seconds')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->json('rule_snapshot')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['festival_edition_id', 'code'], 'legacy_category_code_unique');
        });

        Schema::create('festival_category_option', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_category_id');
            $table->unsignedBigInteger('festival_classification_option_id');
            $table->unique(['festival_category_id', 'festival_classification_option_id'], 'festival_category_option_unique');
        });
    }

    private function seedValidLegacyData(): void
    {
        DB::table('accounts')->insert(['id' => 1]);
        DB::table('festival_editions')->insert(['id' => 10, 'account_id' => 1]);

        DB::table('festival_classification_axes')->insert([
            [
                'id' => 101,
                'account_id' => 1,
                'festival_edition_id' => 10,
                'code' => 'direction-a',
                'name' => 'Direction A axis',
                'kind' => 'direction',
                'is_required' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'id' => 102,
                'account_id' => 1,
                'festival_edition_id' => 10,
                'code' => 'direction-b',
                'name' => 'Direction B axis',
                'kind' => 'direction',
                'is_required' => true,
                'is_active' => false,
                'sort_order' => 10,
            ],
            [
                'id' => 103,
                'account_id' => 1,
                'festival_edition_id' => 10,
                'code' => 'entry-format',
                'name' => 'Entry format',
                'kind' => 'custom',
                'is_required' => false,
                'is_active' => true,
                'sort_order' => 30,
            ],
        ]);

        DB::table('festival_classification_options')->insert([
            [
                'id' => 201,
                'account_id' => 1,
                'festival_edition_id' => 10,
                'festival_classification_axis_id' => 101,
                'code' => 'STAGE',
                'label' => 'Direction A',
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'id' => 202,
                'account_id' => 1,
                'festival_edition_id' => 10,
                'festival_classification_axis_id' => 102,
                'code' => 'stage',
                'label' => 'Direction B',
                'is_active' => false,
                'sort_order' => 10,
            ],
            [
                'id' => 203,
                'account_id' => 1,
                'festival_edition_id' => 10,
                'festival_classification_axis_id' => 102,
                'code' => 'backstage',
                'label' => 'Unused direction',
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'id' => 204,
                'account_id' => 1,
                'festival_edition_id' => 10,
                'festival_classification_axis_id' => 103,
                'code' => 'solo',
                'label' => 'Solo',
                'is_active' => true,
                'sort_order' => 10,
            ],
        ]);

        DB::table('festival_categories')->insert([
            [
                'id' => 301,
                'account_id' => 1,
                'festival_edition_id' => 10,
                'code' => 'junior',
                'name' => 'Junior',
                'workflow' => 'review',
                'rule_snapshot' => json_encode(['legacy' => true], JSON_THROW_ON_ERROR),
                'sort_order' => 10,
            ],
            [
                'id' => 302,
                'account_id' => 1,
                'festival_edition_id' => 10,
                'code' => 'senior',
                'name' => 'Senior',
                'workflow' => 'review',
                'rule_snapshot' => json_encode(['legacy' => true], JSON_THROW_ON_ERROR),
                'sort_order' => 20,
            ],
        ]);

        DB::table('festival_category_option')->insert([
            [
                'id' => 401,
                'account_id' => 1,
                'festival_category_id' => 301,
                'festival_classification_option_id' => 201,
            ],
            [
                'id' => 402,
                'account_id' => 1,
                'festival_category_id' => 302,
                'festival_classification_option_id' => 202,
            ],
            [
                'id' => 403,
                'account_id' => 1,
                'festival_category_id' => 301,
                'festival_classification_option_id' => 204,
            ],
        ]);
    }

    private function expandMigration(): object
    {
        return require database_path('migrations/2026_08_11_072854_festival_directions_01_expand_schema.php');
    }

    private function backfillMigration(): object
    {
        return require database_path('migrations/2026_08_11_072854_festival_directions_02_backfill_data.php');
    }

    private function contractMigration(): object
    {
        return require database_path('migrations/2026_08_11_072854_festival_directions_03_contract_schema.php');
    }
}
