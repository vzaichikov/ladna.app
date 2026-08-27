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
            $table->dropUnique('festival_portal_users_account_id_telegram_user_id_unique');
            $table->unique(
                ['account_id', 'role', 'telegram_user_id'],
                'festival_portal_users_account_role_telegram_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Role-scoped identities can legitimately share a Telegram ID across roles.
        // Reverting to account-wide uniqueness would be destructive and requires a forward fix.
    }
};
