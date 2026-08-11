<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertContractIsSafe();

        $packageForeignKey = collect(Schema::getForeignKeys('festival_edition_purchases'))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['festival_tariff_package_id']);

        if ($packageForeignKey) {
            Schema::table('festival_edition_purchases', function (Blueprint $table) use ($packageForeignKey): void {
                $table->dropForeign($packageForeignKey['name']);
            });
        }

        $packageColumn = collect(Schema::getColumns('festival_edition_purchases'))
            ->firstWhere('name', 'festival_tariff_package_id');

        if ($packageColumn['nullable']) {
            Schema::table('festival_edition_purchases', function (Blueprint $table): void {
                $table->unsignedBigInteger('festival_tariff_package_id')->nullable(false)->change();
            });
        }

        $packageForeignKey = collect(Schema::getForeignKeys('festival_edition_purchases'))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['festival_tariff_package_id']);

        if (! $packageForeignKey) {
            Schema::table('festival_edition_purchases', function (Blueprint $table): void {
                $table->foreign('festival_tariff_package_id')
                    ->references('id')
                    ->on('festival_tariff_packages')
                    ->restrictOnDelete();
            });
        }

        $this->dropColumnsIfPresent('festival_edition_purchases', [
            'tariff_name_snapshot',
            'package_name_snapshot',
            'max_participants',
            'max_tickets',
        ]);
        $this->dropColumnsIfPresent('festival_charges', ['definition_snapshot']);
        $this->dropColumnsIfPresent('festival_charge_adjustments', ['snapshot']);
        $this->dropColumnsIfPresent('festival_results', ['details_snapshot']);
    }

    /**
     * Removed copied Festival configuration cannot be reconstructed.
     */
    public function down(): void {}

    /** @param array<int, string> $columns */
    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($tableName, $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns);
        });
    }

    private function assertContractIsSafe(): void
    {
        $invalidPurchases = DB::table('festival_edition_purchases as purchases')
            ->leftJoin('festival_tariff_packages as packages', 'packages.id', '=', 'purchases.festival_tariff_package_id')
            ->where(function ($query): void {
                $query->whereNull('purchases.festival_tariff_package_id')
                    ->orWhereNull('packages.id')
                    ->orWhereColumn('packages.subscription_plan_id', '!=', 'purchases.subscription_plan_id');
            })
            ->exists();

        if ($invalidPurchases) {
            throw new RuntimeException('Festival relational cleanup stopped: a purchase has no current package from its subscription plan.');
        }
    }
};
