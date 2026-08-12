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
        Schema::table('festival_editions', function (Blueprint $table) {
            $table->string('landing_template')->default('general')->after('rules_html');
            $table->string('landing_palette')->default('general')->after('landing_template');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_editions', function (Blueprint $table) {
            $table->dropColumn(['landing_template', 'landing_palette']);
        });
    }
};
