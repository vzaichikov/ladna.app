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
        DB::table('festival_participants')->update(['member_type' => 'performer']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('festival_participants')->update(['member_type' => 'performer']);
    }
};
