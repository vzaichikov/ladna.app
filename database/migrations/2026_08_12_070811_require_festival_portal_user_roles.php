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
        $invalidCount = DB::table('festival_portal_users')
            ->where(fn ($query) => $query->whereNull('role')->orWhereNotIn('role', ['registrant', 'judge']))
            ->count();

        if ($invalidCount > 0) {
            throw new RuntimeException("Festival portal role contract aborted: {$invalidCount} invalid role rows remain.");
        }

        Schema::table('festival_portal_users', function (Blueprint $table) {
            $table->string('role')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_portal_users', function (Blueprint $table) {
            $table->string('role')->nullable()->change();
        });
    }
};
