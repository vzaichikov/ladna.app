<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('festival_categories')->lockForUpdate()->get(['id']);
            DB::table('festival_category_option')->lockForUpdate()->get(['id']);
            DB::table('festival_classification_options')->lockForUpdate()->get(['id']);
            DB::table('festival_classification_axes')->lockForUpdate()->get(['id']);
            $this->assertLegacyDirectionDataIsValid();

            $directionsByOption = [];
            $usedCodes = [];
            $editionPositions = [];
            $categoryDirections = DB::table('festival_category_option as category_options')
                ->join('festival_classification_options as options', 'options.id', '=', 'category_options.festival_classification_option_id')
                ->join('festival_classification_axes as axes', 'axes.id', '=', 'options.festival_classification_axis_id')
                ->where('axes.kind', 'direction')
                ->orderBy('category_options.festival_category_id')
                ->get(['category_options.festival_category_id', 'options.id as option_id']);
            $referencedOptionIds = $categoryDirections
                ->pluck('option_id')
                ->mapWithKeys(fn (int|string $optionId): array => [(int) $optionId => true]);
            $options = DB::table('festival_classification_options as options')
                ->join('festival_classification_axes as axes', 'axes.id', '=', 'options.festival_classification_axis_id')
                ->where('axes.kind', 'direction')
                ->orderBy('options.festival_edition_id')
                ->orderBy('axes.sort_order')
                ->orderBy('axes.id')
                ->orderBy('options.sort_order')
                ->orderBy('options.id')
                ->lockForUpdate()
                ->get([
                    'options.id',
                    'options.account_id',
                    'options.festival_edition_id',
                    'options.code',
                    'options.label',
                    'options.is_active',
                    'axes.is_active as axis_is_active',
                    'options.created_at',
                    'options.updated_at',
                ]);

            foreach ($options as $option) {
                $editionId = (int) $option->festival_edition_id;
                $usedCodes[$editionId] ??= [];
                $code = $this->uniqueEditionCode((string) $option->code, (int) $option->id, $usedCodes[$editionId]);
                $editionPositions[$editionId] = ($editionPositions[$editionId] ?? 0) + 10;
                $directionsByOption[(int) $option->id] = DB::table('festival_directions')->insertGetId([
                    'account_id' => $option->account_id,
                    'festival_edition_id' => $option->festival_edition_id,
                    'code' => $code,
                    'name' => $option->label,
                    'is_active' => $referencedOptionIds->has((int) $option->id) || ((bool) $option->is_active && (bool) $option->axis_is_active),
                    'sort_order' => $editionPositions[$editionId],
                    'created_at' => $option->created_at,
                    'updated_at' => $option->updated_at,
                ]);
            }

            foreach ($categoryDirections as $categoryDirection) {
                DB::table('festival_categories')
                    ->where('id', $categoryDirection->festival_category_id)
                    ->update(['festival_direction_id' => $directionsByOption[(int) $categoryDirection->option_id]]);
            }
        }, 3);
    }

    /**
     * Direction identifiers cannot be mapped back after the contract migration.
     */
    public function down(): void {}

    private function assertLegacyDirectionDataIsValid(): void
    {
        $categoryEditionMismatch = DB::table('festival_categories as categories')
            ->join('festival_editions as editions', 'editions.id', '=', 'categories.festival_edition_id')
            ->whereColumn('categories.account_id', '!=', 'editions.account_id')
            ->exists();

        if ($categoryEditionMismatch) {
            throw new RuntimeException('Festival direction backfill stopped: a category account does not own its edition.');
        }

        $axisEditionMismatch = DB::table('festival_classification_axes as axes')
            ->join('festival_editions as editions', 'editions.id', '=', 'axes.festival_edition_id')
            ->where('axes.kind', 'direction')
            ->whereColumn('axes.account_id', '!=', 'editions.account_id')
            ->exists();

        if ($axisEditionMismatch) {
            throw new RuntimeException('Festival direction backfill stopped: a direction axis account does not own its edition.');
        }

        $optionEditionMismatch = DB::table('festival_classification_options as options')
            ->join('festival_classification_axes as axes', 'axes.id', '=', 'options.festival_classification_axis_id')
            ->join('festival_editions as editions', 'editions.id', '=', 'options.festival_edition_id')
            ->where('axes.kind', 'direction')
            ->whereColumn('options.account_id', '!=', 'editions.account_id')
            ->exists();

        if ($optionEditionMismatch) {
            throw new RuntimeException('Festival direction backfill stopped: a direction option account does not own its edition.');
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
            throw new RuntimeException('Festival direction backfill stopped: a direction option crosses its axis account or edition boundary.');
        }

        $scopeMismatch = DB::table('festival_category_option as category_options')
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

        if ($scopeMismatch) {
            throw new RuntimeException('Festival direction backfill stopped: a direction link crosses an account or edition boundary.');
        }

        $invalidCategoryIds = $this->invalidLegacyCategoryIds();

        if ($invalidCategoryIds->isNotEmpty()) {
            throw new RuntimeException('Festival direction backfill stopped: every category must have exactly one direction. Invalid category IDs: '.$invalidCategoryIds->implode(', ').'.');
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

    /** @param array<string, true> $usedCodes */
    private function uniqueEditionCode(string $legacyCode, int $optionId, array &$usedCodes): string
    {
        $base = mb_substr($legacyCode, 0, 100);
        $candidate = $base;
        $attempt = 0;

        while (isset($usedCodes[strtolower(rtrim($candidate))])) {
            $attempt++;
            $suffix = '-'.$optionId.($attempt > 1 ? '-'.$attempt : '');
            $candidate = mb_substr($base, 0, 100 - mb_strlen($suffix)).$suffix;
        }

        $usedCodes[strtolower(rtrim($candidate))] = true;

        return $candidate;
    }
};
