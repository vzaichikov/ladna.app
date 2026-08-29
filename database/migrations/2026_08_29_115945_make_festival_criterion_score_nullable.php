<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('festival_criterion_scores', function (Blueprint $table) {
            $table->decimal('score', total: 10, places: 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('festival_criterion_scores')->whereNull('score')->exists()) {
            throw new RuntimeException('Cannot restore a required score while comment-only criterion rows exist.');
        }

        Schema::table('festival_criterion_scores', function (Blueprint $table) {
            $table->decimal('score', total: 10, places: 2)->nullable(false)->change();
        });
    }
};
