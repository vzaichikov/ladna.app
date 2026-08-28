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
            $table->string('member_type')->default('performer')->after('is_profile_owner');
            $table->string('photo_path')->nullable()->after('notes');
            $table->index(
                ['festival_portal_user_id', 'member_type', 'archived_at', 'last_name'],
                'festival_participants_member_type_roster_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_participants', function (Blueprint $table) {
            $table->dropIndex('festival_participants_member_type_roster_idx');
            $table->dropColumn(['member_type', 'photo_path']);
        });
    }
};
