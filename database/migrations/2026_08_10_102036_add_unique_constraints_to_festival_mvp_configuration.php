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
            $table->unique(['festival_edition_id', 'code'], 'festival_category_code_unique');
        });

        Schema::table('festival_workflows', function (Blueprint $table): void {
            $table->unique(['festival_edition_id', 'name'], 'festival_workflow_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_workflows', function (Blueprint $table): void {
            $table->dropUnique('festival_workflow_name_unique');
        });

        Schema::table('festival_categories', function (Blueprint $table): void {
            $table->dropUnique('festival_category_code_unique');
        });
    }
};
