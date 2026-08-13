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
            $table->dropUnique('festival_portal_users_account_id_email_normalized_unique');
            $table->dropUnique('festival_portal_users_account_phone_unique');
            $table->dropUnique('festival_portal_users_account_google_unique');
            $table->unique(['account_id', 'role', 'email_normalized'], 'festival_portal_users_account_role_email_unique');
            $table->unique(['account_id', 'role', 'phone_normalized'], 'festival_portal_users_account_role_phone_unique');
            $table->unique(['account_id', 'role', 'google_id'], 'festival_portal_users_account_role_google_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Role-scoped identities can legitimately duplicate contact details across roles.
        // Reverting to account-wide uniqueness would be destructive and requires a forward fix.
    }
};
