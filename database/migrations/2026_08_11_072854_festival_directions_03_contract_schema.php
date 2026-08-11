<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertContractIsSafe();

        Schema::table('festival_categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('festival_direction_id')->nullable(false)->change();
            $table->dropColumn(['workflow', 'rule_snapshot']);
        });

        Schema::drop('festival_category_option');
        Schema::drop('festival_classification_options');
        Schema::drop('festival_classification_axes');
    }

    /**
     * Removed classification records cannot be reconstructed from directions.
     */
    public function down(): void {}

    private function assertContractIsSafe(): void
    {
        $categoryEditionMismatch = DB::table('festival_categories as categories')
            ->join('festival_editions as editions', 'editions.id', '=', 'categories.festival_edition_id')
            ->whereColumn('categories.account_id', '!=', 'editions.account_id')
            ->exists();

        if ($categoryEditionMismatch) {
            throw new RuntimeException('Festival classification cleanup stopped: a category account does not own its edition.');
        }

        $axisEditionMismatch = DB::table('festival_classification_axes as axes')
            ->join('festival_editions as editions', 'editions.id', '=', 'axes.festival_edition_id')
            ->where('axes.kind', 'direction')
            ->whereColumn('axes.account_id', '!=', 'editions.account_id')
            ->exists();

        if ($axisEditionMismatch) {
            throw new RuntimeException('Festival classification cleanup stopped: a direction axis account does not own its edition.');
        }

        $optionEditionMismatch = DB::table('festival_classification_options as options')
            ->join('festival_classification_axes as axes', 'axes.id', '=', 'options.festival_classification_axis_id')
            ->join('festival_editions as editions', 'editions.id', '=', 'options.festival_edition_id')
            ->where('axes.kind', 'direction')
            ->whereColumn('options.account_id', '!=', 'editions.account_id')
            ->exists();

        if ($optionEditionMismatch) {
            throw new RuntimeException('Festival classification cleanup stopped: a direction option account does not own its edition.');
        }

        $directionEditionMismatch = DB::table('festival_directions as directions')
            ->join('festival_editions as editions', 'editions.id', '=', 'directions.festival_edition_id')
            ->whereColumn('directions.account_id', '!=', 'editions.account_id')
            ->exists();

        if ($directionEditionMismatch) {
            throw new RuntimeException('Festival classification cleanup stopped: a migrated direction account does not own its edition.');
        }

        $invalidLegacyCategoryIds = $this->invalidLegacyCategoryIds();

        if ($invalidLegacyCategoryIds->isNotEmpty()) {
            throw new RuntimeException('Festival classification cleanup stopped: every category must still have exactly one legacy direction. Invalid category IDs: '.$invalidLegacyCategoryIds->implode(', ').'.');
        }

        $optionScopeMismatch = DB::table('festival_classification_options as options')
            ->join('festival_classification_axes as axes', 'axes.id', '=', 'options.festival_classification_axis_id')
            ->where('axes.kind', 'direction')
            ->where(function ($query): void {
                $query->whereColumn('options.account_id', '!=', 'axes.account_id')
                    ->orWhereColumn('options.festival_edition_id', '!=', 'axes.festival_edition_id');
            })
            ->exists();

        if ($optionScopeMismatch) {
            throw new RuntimeException('Festival classification cleanup stopped: a direction option crosses its axis account or edition boundary.');
        }

        $legacyScopeMismatch = DB::table('festival_category_option as category_options')
            ->join('festival_categories as categories', 'categories.id', '=', 'category_options.festival_category_id')
            ->join('festival_classification_options as options', 'options.id', '=', 'category_options.festival_classification_option_id')
            ->join('festival_classification_axes as axes', 'axes.id', '=', 'options.festival_classification_axis_id')
            ->where('axes.kind', 'direction')
            ->where(function ($query): void {
                $query->whereColumn('category_options.account_id', '!=', 'categories.account_id')
                    ->orWhereColumn('options.account_id', '!=', 'categories.account_id')
                    ->orWhereColumn('options.festival_edition_id', '!=', 'categories.festival_edition_id')
                    ->orWhereColumn('axes.account_id', '!=', 'categories.account_id')
                    ->orWhereColumn('axes.festival_edition_id', '!=', 'categories.festival_edition_id');
            })
            ->exists();

        if ($legacyScopeMismatch) {
            throw new RuntimeException('Festival classification cleanup stopped: a legacy direction link crosses an account or edition boundary.');
        }

        $newDirectionMismatch = DB::table('festival_categories as categories')
            ->leftJoin('festival_directions as directions', 'directions.id', '=', 'categories.festival_direction_id')
            ->where(function ($query): void {
                $query->whereNull('categories.festival_direction_id')
                    ->orWhereNull('directions.id')
                    ->orWhereColumn('directions.account_id', '!=', 'categories.account_id')
                    ->orWhereColumn('directions.festival_edition_id', '!=', 'categories.festival_edition_id')
                    ->orWhere('directions.is_active', false);
            })
            ->exists();

        if ($newDirectionMismatch) {
            throw new RuntimeException('Festival classification cleanup stopped: a category has no valid account- and edition-scoped direction.');
        }

        $legacyDirectionCount = DB::table('festival_classification_options as options')
            ->join('festival_classification_axes as axes', 'axes.id', '=', 'options.festival_classification_axis_id')
            ->where('axes.kind', 'direction')
            ->count();

        if ($legacyDirectionCount !== DB::table('festival_directions')->count()) {
            throw new RuntimeException('Festival classification cleanup stopped: not every legacy direction was migrated.');
        }
    }

    /** @return Collection<int, int> */
    private function invalidLegacyCategoryIds(): Collection
    {
        $directionCounts = DB::table('festival_category_option as category_options')
            ->join('festival_classification_options as options', 'options.id', '=', 'category_options.festival_classification_option_id')
            ->join('festival_classification_axes as axes', 'axes.id', '=', 'options.festival_classification_axis_id')
            ->where('axes.kind', 'direction')
            ->distinct()
            ->get(['category_options.festival_category_id', 'options.id'])
            ->groupBy(fn (object $link): int => (int) $link->festival_category_id)
            ->map->count();

        return DB::table('festival_categories')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int|string $categoryId): int => (int) $categoryId)
            ->filter(fn (int $categoryId): bool => (int) $directionCounts->get($categoryId, 0) !== 1)
            ->values();
    }
};
