<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('class_types')
            ->whereNull('cancellation_cutoff_minutes')
            ->update(['cancellation_cutoff_minutes' => 1440]);

        DB::table('schedule_series')
            ->whereNull('cancellation_cutoff_minutes')
            ->update(['cancellation_cutoff_minutes' => 1440]);

        DB::table('scheduled_classes')
            ->whereNull('cancellation_cutoff_minutes')
            ->update(['cancellation_cutoff_minutes' => 1440]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally irreversible data backfill. The schema rollback drops the column.
    }
};
