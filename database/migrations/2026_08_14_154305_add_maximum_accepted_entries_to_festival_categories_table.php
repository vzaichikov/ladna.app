<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('festival_categories', function (Blueprint $table): void {
            $table->unsignedSmallInteger('maximum_accepted_entries')->nullable()->after('minimum_entries_to_run');
        });
        Schema::table('festival_entries', function (Blueprint $table): void {
            $table->index(['festival_category_id', 'status'], 'festival_entries_category_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_entries', function (Blueprint $table): void {
            $table->dropIndex('festival_entries_category_status_idx');
        });
        Schema::table('festival_categories', function (Blueprint $table): void {
            $table->dropColumn('maximum_accepted_entries');
        });
    }
};
