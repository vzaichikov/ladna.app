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
            $table->string('competition_format', 20)->default('scored')->after('requirements_html');
            $table->unsignedSmallInteger('minimum_entries_to_run')->default(1)->after('competition_format');
            $table->index(['festival_edition_id', 'competition_format', 'is_active'], 'festival_categories_format_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_categories', function (Blueprint $table): void {
            $table->dropIndex('festival_categories_format_active_idx');
            $table->dropColumn(['competition_format', 'minimum_entries_to_run']);
        });
    }
};
