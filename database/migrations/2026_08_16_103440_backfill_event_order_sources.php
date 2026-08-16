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
        DB::table('event_orders')
            ->whereNotNull('issued_by')
            ->orWhere('provider', 'like', 'manual\_%')
            ->update(['source' => 'manual']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
