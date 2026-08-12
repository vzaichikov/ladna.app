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
        Schema::table('festival_participants', function (Blueprint $table) {
            $table->boolean('is_profile_owner')->nullable()->after('festival_portal_user_id');
            $table->unique(['festival_portal_user_id', 'is_profile_owner'], 'festival_participants_profile_owner_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_participants', function (Blueprint $table) {
            $table->dropUnique('festival_participants_profile_owner_unique');
            $table->dropColumn('is_profile_owner');
        });
    }
};
