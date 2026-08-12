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
            $table->string('role')->nullable()->after('account_id');
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('password')->nullable()->after('email_normalized');
            $table->string('google_id')->nullable()->after('password');
            $table->string('phone_normalized')->nullable()->after('phone');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('email')->nullable()->change();
            $table->string('email_normalized')->nullable()->change();
            $table->unique(['account_id', 'phone_normalized'], 'festival_portal_users_account_phone_unique');
            $table->unique(['account_id', 'google_id'], 'festival_portal_users_account_google_unique');
            $table->index(['account_id', 'role', 'is_active'], 'festival_portal_users_account_role_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_portal_users', function (Blueprint $table) {
            $table->dropIndex('festival_portal_users_account_role_active_index');
            $table->dropUnique('festival_portal_users_account_phone_unique');
            $table->dropUnique('festival_portal_users_account_google_unique');
            $table->dropColumn(['role', 'is_active', 'password', 'google_id', 'phone_normalized', 'phone_verified_at']);
            $table->string('email')->nullable(false)->change();
            $table->string('email_normalized')->nullable(false)->change();
        });
    }
};
