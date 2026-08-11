<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FestivalRelationalCleanupMigrationTest extends TestCase
{
    public function test_it_preserves_relational_state_and_transaction_facts_while_removing_copies(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();

            $this->expandMigration()->up();
            $this->backfillMigration()->up();
            $this->entryContractMigration()->up();
            $this->financialContractMigration()->up();

            $this->assertSame('changes_requested', DB::table('festival_entry_steps')->where('id', 80)->value('status'));
            $this->assertSame('2026-09-01 12:00:00', (string) DB::table('festival_entry_steps')->where('id', 80)->value('correction_due_at'));
            $this->assertSame(101, DB::table('festival_entry_steps')->where('id', 80)->value('festival_workflow_step_id'));
            $this->assertSame(200, DB::table('festival_categories')->where('id', 30)->value('festival_workflow_id'));
            $this->assertSame('submitted', DB::table('festival_entry_requirements')->where('id', 90)->value('status'));
            $this->assertSame(12_345, DB::table('festival_edition_purchases')->where('id', 110)->value('amount_cents'));
            $this->assertSame('paid', DB::table('festival_edition_purchases')->where('id', 110)->value('status'));
            $this->assertSame(5_000, DB::table('festival_charges')->where('id', 120)->value('amount_cents'));
            $this->assertSame(2_000, DB::table('festival_charge_adjustments')->where('id', 130)->value('amount_cents'));
            $this->assertSame('9.7500', (string) DB::table('festival_results')->where('id', 140)->value('total_score'));
            $this->assertSame(1, DB::table('festival_results')->where('id', 140)->value('rank'));

            foreach ($this->removedEntryColumns() as [$table, $column]) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column} still exists");
            }

            foreach ($this->removedFinancialColumns() as [$table, $column]) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column} still exists");
            }

            $workflowStepColumn = collect(Schema::getColumns('festival_entry_steps'))->firstWhere('name', 'festival_workflow_step_id');
            $packageColumn = collect(Schema::getColumns('festival_edition_purchases'))->firstWhere('name', 'festival_tariff_package_id');
            $this->assertIsArray($workflowStepColumn);
            $this->assertFalse($workflowStepColumn['nullable']);
            $this->assertIsArray($packageColumn);
            $this->assertFalse($packageColumn['nullable']);

            try {
                DB::table('festival_entry_steps')->insert([
                    'id' => 81,
                    'account_id' => 1,
                    'festival_entry_id' => 70,
                    'festival_workflow_step_id' => 101,
                    'status' => 'draft',
                ]);
                $this->fail('The relational entry-step key accepted duplicate progress.');
            } catch (QueryException) {
                $this->assertSame(1, DB::table('festival_entry_steps')->count());
            }

            try {
                DB::table('festival_workflow_steps')->where('id', 101)->delete();
                $this->fail('A referenced workflow step was deleted.');
            } catch (QueryException) {
                $this->assertSame(1, DB::table('festival_workflow_steps')->where('id', 101)->count());
            }

            try {
                DB::table('festival_tariff_packages')->where('id', 60)->delete();
                $this->fail('A referenced Festival package was deleted.');
            } catch (QueryException) {
                $this->assertSame(1, DB::table('festival_tariff_packages')->where('id', 60)->count());
            }
        });
    }

    public function test_entry_contract_aborts_before_cleanup_for_a_cross_edition_workflow_step(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();
            $this->expandMigration()->up();
            $this->backfillMigration()->up();
            DB::table('festival_workflows')->where('id', 100)->update(['festival_edition_id' => 11]);

            try {
                $this->entryContractMigration()->up();
                $this->fail('The entry contract accepted a workflow step from another edition.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('account- and edition-scoped workflow step', $exception->getMessage());
            }

            $this->assertTrue(Schema::hasColumn('festival_entry_steps', 'step_snapshot'));
            $this->assertTrue(Schema::hasColumn('festival_entries', 'category_snapshot'));
            $this->assertSame('2026-09-01 12:00:00', (string) DB::table('festival_entry_steps')->where('id', 80)->value('correction_due_at'));
        });
    }

    public function test_entry_contract_aborts_before_cleanup_for_duplicate_step_progress(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();
            DB::table('festival_entry_steps')->insert([
                'id' => 81,
                'account_id' => 1,
                'festival_entry_id' => 70,
                'festival_workflow_step_id' => 101,
                'code' => 'legacy-second-code',
                'type' => 'form',
                'title' => 'Second copied title',
                'sort_order' => 20,
                'review_mode' => 'automatic',
                'review_effect' => 'none',
                'status' => 'draft',
            ]);
            $this->expandMigration()->up();
            $this->backfillMigration()->up();

            try {
                $this->entryContractMigration()->up();
                $this->fail('The entry contract accepted duplicate workflow-step progress.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('duplicate progress rows', $exception->getMessage());
            }

            $this->assertTrue(Schema::hasColumn('festival_entry_steps', 'code'));
            $this->assertSame(2, DB::table('festival_entry_steps')->count());
        });
    }

    public function test_entry_contract_aborts_for_cross_account_and_invalid_participant_ownership(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();
            $this->expandMigration()->up();
            $this->backfillMigration()->up();

            DB::table('festival_portal_users')->where('id', 20)->update(['account_id' => 2]);
            try {
                $this->entryContractMigration()->up();
                $this->fail('The entry contract accepted a registrant from another account.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('entry has an invalid account-', $exception->getMessage());
            }
            DB::table('festival_portal_users')->where('id', 20)->update(['account_id' => 1]);

            DB::table('festival_portal_users')->insert(['id' => 21, 'account_id' => 1]);
            DB::table('festival_participants')->where('id', 40)->update(['festival_portal_user_id' => 21]);
            try {
                $this->entryContractMigration()->up();
                $this->fail('The entry contract accepted another registrant\'s participant.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('account or registrant boundary', $exception->getMessage());
            }
            DB::table('festival_participants')->where('id', 40)->update(['festival_portal_user_id' => 20]);

            DB::table('festival_participants')->insert([
                'id' => 41,
                'account_id' => 1,
                'festival_portal_user_id' => 20,
                'first_name' => 'Unattached',
                'last_name' => 'Participant',
                'date_of_birth' => '2011-01-01',
            ]);
            DB::table('festival_entry_requirements')->where('id', 90)->update(['festival_participant_id' => 41]);
            try {
                $this->entryContractMigration()->up();
                $this->fail('The entry contract accepted a requirement subject who is not attached to the entry.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('invalid current definition, step, or participant', $exception->getMessage());
            }

            $this->assertTrue(Schema::hasColumn('festival_entry_steps', 'step_snapshot'));
            $this->assertTrue(Schema::hasColumn('festival_entry_requirements', 'definition_snapshot'));
        });
    }

    public function test_financial_contract_aborts_before_cleanup_without_a_current_package(): void
    {
        $this->withIsolatedMigrationDatabase(function (): void {
            $this->seedValidLegacyData();
            DB::table('festival_edition_purchases')->where('id', 110)->update(['festival_tariff_package_id' => null]);

            try {
                $this->financialContractMigration()->up();
                $this->fail('The financial contract accepted a purchase without a package.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('no current package', $exception->getMessage());
            }

            $this->assertTrue(Schema::hasColumn('festival_edition_purchases', 'package_name_snapshot'));
            $this->assertTrue(Schema::hasColumn('festival_results', 'details_snapshot'));
            $this->assertSame(12_345, DB::table('festival_edition_purchases')->where('id', 110)->value('amount_cents'));
        });
    }

    private function withIsolatedMigrationDatabase(callable $callback): void
    {
        $originalConnection = config('database.default');
        $connection = 'festival_relational_cleanup_testing';
        $originalConfig = config("database.connections.{$originalConnection}");
        $database = (string) ($originalConfig['database'] ?? '');

        if (($originalConfig['driver'] ?? null) !== 'mysql' || ! str_ends_with($database, '_testing')) {
            $this->markTestSkipped('Festival relational cleanup tests require the explicitly dedicated MySQL test database.');
        }

        $prefix = bin2hex(random_bytes(1)).'_';

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

    private function createLegacySchema(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('festival_editions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
        });
        Schema::create('festival_portal_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
        });
        Schema::create('festival_workflows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_edition_id');
        });
        Schema::create('festival_workflow_steps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_workflow_id');
            $table->string('code');
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
        });
        Schema::create('festival_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_edition_id');
            $table->unsignedBigInteger('festival_workflow_id')->nullable();
        });
        Schema::create('festival_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_portal_user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth');
        });
        Schema::create('festival_requirement_definitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_edition_id');
            $table->string('name');
        });
        Schema::create('festival_tariff_packages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('subscription_plan_id');
            $table->string('name');
            $table->unsignedInteger('max_participants');
            $table->unsignedInteger('max_tickets');
        });

        Schema::create('festival_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_edition_id');
            $table->unsignedBigInteger('festival_portal_user_id');
            $table->unsignedBigInteger('festival_category_id');
            $table->string('coach_name_snapshot')->nullable();
            $table->string('studio_name_snapshot')->nullable();
            $table->json('category_snapshot')->nullable();
            $table->json('registrant_snapshot')->nullable();
            $table->json('workflow_snapshot')->nullable();
        });
        Schema::create('festival_entry_participant', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_entry_id');
            $table->unsignedBigInteger('festival_participant_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('age_snapshot');
            $table->string('name_snapshot');
            $table->json('participant_snapshot')->nullable();
        });
        Schema::create('festival_entry_steps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_entry_id');
            $table->unsignedBigInteger('festival_workflow_step_id')->nullable();
            $table->string('code');
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('review_mode')->default('automatic');
            $table->string('review_effect')->default('none');
            $table->string('status')->default('draft');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('revision_due_at')->nullable();
            $table->json('step_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['festival_entry_id', 'code'], 'festival_entry_step_code_unique');
            $table->index(['festival_entry_id', 'sort_order']);
            $table->foreign('festival_workflow_step_id')->references('id')->on('festival_workflow_steps')->nullOnDelete();
        });
        Schema::create('festival_entry_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('festival_entry_id');
            $table->unsignedBigInteger('festival_entry_step_id')->nullable();
            $table->unsignedBigInteger('festival_requirement_definition_id');
            $table->unsignedBigInteger('festival_participant_id')->nullable();
            $table->string('subject_scope')->default('entry');
            $table->string('subject_key')->default('entry');
            $table->string('status')->default('missing');
            $table->json('definition_snapshot');
            $table->boolean('is_required')->default(true);
            $table->timestamp('due_at')->nullable();
        });
        Schema::create('festival_edition_purchases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('subscription_plan_id');
            $table->unsignedBigInteger('festival_tariff_package_id')->nullable();
            $table->string('status');
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('tariff_name_snapshot');
            $table->string('package_name_snapshot');
            $table->unsignedInteger('max_participants');
            $table->unsignedInteger('max_tickets');
            $table->foreign('festival_tariff_package_id')->references('id')->on('festival_tariff_packages')->nullOnDelete();
        });
        Schema::create('festival_charges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('amount_cents');
            $table->json('definition_snapshot')->nullable();
        });
        Schema::create('festival_charge_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('amount_cents');
            $table->json('snapshot')->nullable();
        });
        Schema::create('festival_results', function (Blueprint $table): void {
            $table->id();
            $table->decimal('total_score', 12, 4);
            $table->unsignedInteger('rank')->nullable();
            $table->string('medal')->nullable();
            $table->json('details_snapshot');
        });
    }

    private function seedValidLegacyData(): void
    {
        DB::table('accounts')->insert([['id' => 1], ['id' => 2]]);
        DB::table('subscription_plans')->insert(['id' => 1]);
        DB::table('festival_editions')->insert([
            ['id' => 10, 'account_id' => 1],
            ['id' => 11, 'account_id' => 2],
        ]);
        DB::table('festival_portal_users')->insert(['id' => 20, 'account_id' => 1]);
        DB::table('festival_workflows')->insert([
            ['id' => 100, 'account_id' => 1, 'festival_edition_id' => 10],
            ['id' => 200, 'account_id' => 1, 'festival_edition_id' => 10],
        ]);
        DB::table('festival_workflow_steps')->insert(['id' => 101, 'account_id' => 1, 'festival_workflow_id' => 100, 'code' => 'current-step', 'title' => 'Current step title', 'sort_order' => 10]);
        DB::table('festival_categories')->insert(['id' => 30, 'account_id' => 1, 'festival_edition_id' => 10, 'festival_workflow_id' => 200]);
        DB::table('festival_participants')->insert(['id' => 40, 'account_id' => 1, 'festival_portal_user_id' => 20, 'first_name' => 'Current', 'last_name' => 'Participant', 'date_of_birth' => '2010-01-01']);
        DB::table('festival_requirement_definitions')->insert(['id' => 50, 'account_id' => 1, 'festival_edition_id' => 10, 'name' => 'Current requirement']);
        DB::table('festival_tariff_packages')->insert(['id' => 60, 'subscription_plan_id' => 1, 'name' => 'Current package', 'max_participants' => 100, 'max_tickets' => 300]);
        DB::table('festival_entries')->insert([
            'id' => 70,
            'account_id' => 1,
            'festival_edition_id' => 10,
            'festival_portal_user_id' => 20,
            'festival_category_id' => 30,
            'coach_name_snapshot' => 'Old coach',
            'studio_name_snapshot' => 'Old studio',
            'category_snapshot' => json_encode(['name' => 'Old category'], JSON_THROW_ON_ERROR),
            'registrant_snapshot' => json_encode(['name' => 'Old registrant'], JSON_THROW_ON_ERROR),
            'workflow_snapshot' => json_encode(['name' => 'Old workflow'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('festival_entry_participant')->insert([
            'id' => 71,
            'account_id' => 1,
            'festival_entry_id' => 70,
            'festival_participant_id' => 40,
            'age_snapshot' => 12,
            'name_snapshot' => 'Old participant',
            'participant_snapshot' => json_encode(['name' => 'Old participant'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('festival_entry_steps')->insert([
            'id' => 80,
            'account_id' => 1,
            'festival_entry_id' => 70,
            'festival_workflow_step_id' => 101,
            'code' => 'legacy-code',
            'type' => 'form',
            'title' => 'Old copied title',
            'sort_order' => 10,
            'review_mode' => 'organizer',
            'review_effect' => 'qualification',
            'status' => 'changes_requested',
            'revision_due_at' => '2026-09-01 12:00:00',
            'step_snapshot' => json_encode(['title' => 'Old copied title'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('festival_entry_requirements')->insert([
            'id' => 90,
            'account_id' => 1,
            'festival_entry_id' => 70,
            'festival_entry_step_id' => 80,
            'festival_requirement_definition_id' => 50,
            'festival_participant_id' => 40,
            'subject_scope' => 'participant',
            'subject_key' => 'participant:40',
            'status' => 'submitted',
            'definition_snapshot' => json_encode(['name' => 'Old requirement'], JSON_THROW_ON_ERROR),
            'is_required' => true,
        ]);
        DB::table('festival_edition_purchases')->insert([
            'id' => 110,
            'account_id' => 1,
            'subscription_plan_id' => 1,
            'festival_tariff_package_id' => 60,
            'status' => 'paid',
            'amount_cents' => 12_345,
            'currency' => 'UAH',
            'tariff_name_snapshot' => 'Old plan',
            'package_name_snapshot' => 'Old package',
            'max_participants' => 50,
            'max_tickets' => 150,
        ]);
        DB::table('festival_charges')->insert(['id' => 120, 'amount_cents' => 5_000, 'definition_snapshot' => json_encode(['name' => 'Old fee'], JSON_THROW_ON_ERROR)]);
        DB::table('festival_charge_adjustments')->insert(['id' => 130, 'amount_cents' => 2_000, 'snapshot' => json_encode(['reason' => 'Old pricing'], JSON_THROW_ON_ERROR)]);
        DB::table('festival_results')->insert(['id' => 140, 'total_score' => 9.75, 'rank' => 1, 'medal' => 'gold', 'details_snapshot' => json_encode(['sheets' => [1]], JSON_THROW_ON_ERROR)]);
    }

    /** @return array<int, array{string, string}> */
    private function removedEntryColumns(): array
    {
        return [
            ['festival_entries', 'coach_name_snapshot'],
            ['festival_entries', 'studio_name_snapshot'],
            ['festival_entries', 'category_snapshot'],
            ['festival_entries', 'registrant_snapshot'],
            ['festival_entries', 'workflow_snapshot'],
            ['festival_entry_participant', 'age_snapshot'],
            ['festival_entry_participant', 'name_snapshot'],
            ['festival_entry_participant', 'participant_snapshot'],
            ['festival_entry_steps', 'code'],
            ['festival_entry_steps', 'step_snapshot'],
            ['festival_entry_steps', 'revision_due_at'],
            ['festival_entry_requirements', 'definition_snapshot'],
            ['festival_entry_requirements', 'subject_scope'],
            ['festival_entry_requirements', 'is_required'],
            ['festival_entry_requirements', 'due_at'],
        ];
    }

    /** @return array<int, array{string, string}> */
    private function removedFinancialColumns(): array
    {
        return [
            ['festival_edition_purchases', 'tariff_name_snapshot'],
            ['festival_edition_purchases', 'package_name_snapshot'],
            ['festival_edition_purchases', 'max_participants'],
            ['festival_edition_purchases', 'max_tickets'],
            ['festival_charges', 'definition_snapshot'],
            ['festival_charge_adjustments', 'snapshot'],
            ['festival_results', 'details_snapshot'],
        ];
    }

    private function dropIsolatedMigrationTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'festival_results',
            'festival_charge_adjustments',
            'festival_charges',
            'festival_edition_purchases',
            'festival_entry_requirements',
            'festival_entry_steps',
            'festival_entry_participant',
            'festival_entries',
            'festival_tariff_packages',
            'festival_requirement_definitions',
            'festival_participants',
            'festival_categories',
            'festival_workflow_steps',
            'festival_workflows',
            'festival_portal_users',
            'festival_editions',
            'subscription_plans',
            'accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    private function expandMigration(): object
    {
        return require database_path('migrations/2026_08_11_174847_festival_relational_cleanup_01_expand_schema.php');
    }

    private function backfillMigration(): object
    {
        return require database_path('migrations/2026_08_11_174848_festival_relational_cleanup_02_backfill_correction_deadlines.php');
    }

    private function entryContractMigration(): object
    {
        return require database_path('migrations/2026_08_11_174848_festival_relational_cleanup_03_contract_entry_schema.php');
    }

    private function financialContractMigration(): object
    {
        return require database_path('migrations/2026_08_11_174848_festival_relational_cleanup_04_contract_financial_schema.php');
    }
}
