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
        $slots = DB::table('festival_schedule_slots')
            ->orderBy('festival_stage_id')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['id', 'festival_stage_id']);

        foreach ($slots->groupBy('festival_stage_id') as $stageSlots) {
            foreach ($stageSlots->values() as $index => $slot) {
                DB::table('festival_schedule_slots')
                    ->where('id', $slot->id)
                    ->update(['sort_order' => ($index + 1) * 10]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('festival_schedule_slots')->update(['sort_order' => 0]);
    }
};
