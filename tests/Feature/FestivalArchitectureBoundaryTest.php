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
            'festival_series', 'festival_editions', 'festival_portal_users', 'festival_participants',
            'festival_directions', 'festival_categories', 'festival_entries', 'festival_charges', 'festival_payment_attempts',
            'festival_schedule_slots', 'festival_score_sheets', 'festival_ticket_orders', 'festival_tickets',
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

    public function test_repository_does_not_use_a_root_docs_directory(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('docs'));
    }
}
