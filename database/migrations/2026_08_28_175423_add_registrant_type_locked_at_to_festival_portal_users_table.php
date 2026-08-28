<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('festival_portal_users', function (Blueprint $table) {
            $table->timestamp('registrant_type_locked_at')->nullable()->after('registrant_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_portal_users', function (Blueprint $table) {
            $table->dropColumn('registrant_type_locked_at');
        });
    }
};
