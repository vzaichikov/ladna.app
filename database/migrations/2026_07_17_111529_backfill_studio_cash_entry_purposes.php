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
        DB::table('studio_cash_entries')
            ->where('direction', 'cash_out')
            ->update(['purpose' => 'owner_withdrawal']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('studio_cash_entries')
            ->where('purpose', 'owner_withdrawal')
            ->update(['purpose' => 'deposit']);
    }
};
