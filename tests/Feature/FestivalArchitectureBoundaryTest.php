<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

class FestivalArchitectureBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_festival_schema_contains_no_customer_or_event_foreign_keys(): void
    {
        $tables = [
            'festival_series', 'festival_editions', 'festival_portal_users', 'festival_otp_challenges', 'festival_participants',
            'festival_directions', 'festival_categories', 'festival_entries', 'festival_charges', 'festival_payment_attempts',
            'festival_schedule_slots', 'festival_timelines', 'festival_timeline_items', 'festival_score_sheets', 'festival_ticket_orders', 'festival_tickets',
            'festival_online_streams', 'festival_stream_entitlements', 'festival_stream_ip_leases',
        ];

        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);
            $this->assertNotContains('customer_id', $columns, "{$table} links to Customer");
            $this->assertNotContains('event_id', $columns, "{$table} links to Event");
        }
    }

    public function test_festival_domain_source_has_no_customer_or_event_model_dependency(): void
    {
        $directories = [
            app_path('Actions/Festivals'),
            app_path('Support/Festivals'),
        ];
        $files = collect($directories)->flatMap(function (string $directory): array {
            $iterator = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)), '/\.php$/');

            return iterator_to_array($iterator, false);
        });
        $festivalModels = glob(app_path('Models/Festival*.php')) ?: [];

        $festivalControllers = [
            app_path('Http/Controllers/FestivalWorkspaceController.php'),
            app_path('Http/Controllers/FestivalSeriesController.php'),
            app_path('Http/Controllers/FestivalTimelineController.php'),
            app_path('Http/Controllers/FestivalOnlineStreamController.php'),
            app_path('Http/Controllers/FestivalStreamAccessController.php'),
            app_path('Jobs/AdvanceFestivalTimelineJob.php'),
            app_path('View/Composers/FestivalWorkspaceComposer.php'),
        ];

        foreach ($files->merge($festivalModels)->merge($festivalControllers) as $file) {
            $contents = file_get_contents((string) $file);
            $this->assertDoesNotMatchRegularExpression('/\bCustomer\b/', $contents, (string) $file);
            $this->assertDoesNotMatchRegularExpression('/\bEvent\b/', $contents, (string) $file);
        }
    }

    public function test_festival_routes_expose_only_the_payment_callback_as_json_api(): void
    {
        $festivalApiRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'festival') && str_starts_with($route->uri(), 'api/'));

        $this->assertCount(1, $festivalApiRoutes);
        $this->assertSame('api/v1/festival-payments/{provider}/callbacks', $festivalApiRoutes->first()->uri());

        $internalStreamRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'internal/festival-stream/'))
            ->map(fn ($route): string => $route->uri())
            ->values()
            ->all();
        $this->assertSame([
            'internal/festival-stream/authorize',
            'internal/festival-stream/publisher-authorize',
        ], $internalStreamRoutes);
    }

    public function test_optional_stream_schema_has_single_edition_and_guest_entitlement_constraints(): void
    {
        $streamIndexes = collect(Schema::getIndexes('festival_online_streams'));
        $this->assertTrue($streamIndexes->contains(fn (array $index): bool => $index['columns'] === ['festival_edition_id'] && $index['unique']));
        $this->assertTrue($streamIndexes->contains(fn (array $index): bool => $index['columns'] === ['path'] && $index['unique']));

        $entitlementIndexes = collect(Schema::getIndexes('festival_stream_entitlements'));
        $this->assertTrue($entitlementIndexes->contains(fn (array $index): bool => $index['columns'] === ['festival_ticket_id'] && $index['unique']));
        $this->assertTrue($entitlementIndexes->contains(fn (array $index): bool => $index['columns'] === ['festival_online_stream_id', 'festival_portal_user_id'] && $index['unique']));
        $this->assertTrue($entitlementIndexes->contains(fn (array $index): bool => $index['columns'] === ['account_id', 'festival_portal_user_id']));

        $leaseIndexes = collect(Schema::getIndexes('festival_stream_ip_leases'));
        $this->assertTrue($leaseIndexes->contains(fn (array $index): bool => $index['columns'] === ['festival_stream_entitlement_id', 'ip_hash'] && $index['unique']));
    }

    public function test_festival_authentication_has_no_magic_link_runtime_or_storage(): void
    {
        $this->assertFalse(Schema::hasTable('festival_login_tokens'));
        $this->assertTrue(Schema::hasTable('festival_otp_challenges'));
        $this->assertFileDoesNotExist(app_path('Actions/Festivals/FestivalMagicLink.php'));
        $this->assertFileDoesNotExist(app_path('Models/FestivalLoginToken.php'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('festival.login.request'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('festival.login.consume'));
        $this->assertFileExists(app_path('Mail/FestivalPortalMail.php'));
    }

    public function test_live_classification_feature_and_legacy_category_columns_are_absent(): void
    {
        $this->assertFalse(Schema::hasTable('festival_classification_axes'));
        $this->assertFalse(Schema::hasTable('festival_classification_options'));
        $this->assertFalse(Schema::hasTable('festival_category_option'));
        $this->assertTrue(Schema::hasTable('festival_directions'));
        $this->assertTrue(Schema::hasColumn('festival_categories', 'festival_direction_id'));
        $this->assertTrue(Schema::hasColumn('festival_categories', 'requirements_html'));
        $this->assertFalse(Schema::hasColumn('festival_categories', 'workflow'));
        $this->assertFalse(Schema::hasColumn('festival_categories', 'rule_snapshot'));

        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('dashboard.accounts.festivals.settings.classifications'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('dashboard.accounts.festivals.axes.store'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('dashboard.accounts.festivals.axis-options.store'));
        $this->assertFileDoesNotExist(app_path('Models/FestivalClassificationAxis.php'));
        $this->assertFileDoesNotExist(app_path('Models/FestivalClassificationOption.php'));
    }

    public function test_festival_runtime_schema_uses_current_relations_without_snapshot_or_revision_columns(): void
    {
        $prohibitedColumns = [
            'snapshot',
            'version',
            'lock_version',
            'revision_due_at',
        ];

        foreach (Schema::getTableListing() as $table) {
            if (! str_starts_with($table, 'festival_')) {
                continue;
            }

            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertFalse(
                    in_array($column, $prohibitedColumns, true) || str_ends_with($column, '_snapshot'),
                    "{$table}.{$column} restores removed Festival history infrastructure",
                );
            }
        }

        $this->assertTrue(Schema::hasColumn('festival_entry_steps', 'correction_due_at'));

        $stepIndexes = collect(Schema::getIndexes('festival_entry_steps'))->pluck('name');
        $this->assertNotContains('festival_entry_step_code_unique', $stepIndexes);
        $this->assertContains('festival_entry_workflow_step_unique', $stepIndexes);

        $stepForeignKey = collect(Schema::getForeignKeys('festival_entry_steps'))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['festival_workflow_step_id']);
        $packageForeignKey = collect(Schema::getForeignKeys('festival_edition_purchases'))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['festival_tariff_package_id']);

        $this->assertSame('restrict', strtolower((string) $stepForeignKey['on_delete']));
        $this->assertSame('restrict', strtolower((string) $packageForeignKey['on_delete']));

        $this->assertFalse(Schema::hasColumn('festival_timeline_items', 'status'));
        $timelineIndexes = collect(Schema::getIndexes('festival_timelines'));
        $this->assertTrue($timelineIndexes->contains(fn (array $index): bool => $index['columns'] === ['festival_edition_id', 'festival_stage_id'] && $index['unique']));
        $sourceSlotForeignKey = collect(Schema::getForeignKeys('festival_timeline_items'))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['festival_schedule_slot_id']);
        $this->assertSame('festival_schedule_slots', $sourceSlotForeignKey['foreign_table']);
        $this->assertSame('set null', strtolower((string) $sourceSlotForeignKey['on_delete']));
    }

    public function test_repository_does_not_use_a_root_docs_directory(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('docs'));
    }
}
