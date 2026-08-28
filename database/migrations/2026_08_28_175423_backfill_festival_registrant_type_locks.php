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
        DB::table('festival_portal_users')
            ->whereNull('registrant_type_locked_at')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('festival_entries')
                    ->whereColumn('festival_entries.festival_portal_user_id', 'festival_portal_users.id');
            })
            ->update([
                'registrant_type_locked_at' => DB::raw('(select min(festival_entries.created_at) from festival_entries where festival_entries.festival_portal_user_id = festival_portal_users.id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('festival_portal_users')->update(['registrant_type_locked_at' => null]);
    }
};
