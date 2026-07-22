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
        DB::table('accounts')
            ->where('slug', 'ladna-demo')
            ->where('mode', 'demo_readonly')
            ->where('public_schedule_view', 'classic')
            ->update(['public_schedule_view' => 'compact_booking']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('accounts')
            ->where('slug', 'ladna-demo')
            ->where('mode', 'demo_readonly')
            ->where('public_schedule_view', 'compact_booking')
            ->update(['public_schedule_view' => 'classic']);
    }
};
