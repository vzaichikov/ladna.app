<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('festival_entry_steps')->lockForUpdate()->get(['id']);

            DB::table('festival_entry_steps')
                ->whereNotNull('revision_due_at')
                ->update(['correction_due_at' => DB::raw('revision_due_at')]);
        }, 3);
    }

    /**
     * Correction deadlines cannot be copied back after the contract migration.
     */
    public function down(): void {}
};
