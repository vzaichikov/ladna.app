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
        Schema::table('festival_requirement_definitions', function (Blueprint $table) {
            $table->boolean('show_in_media_report')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_requirement_definitions', function (Blueprint $table) {
            $table->dropColumn('show_in_media_report');
        });
    }
};
