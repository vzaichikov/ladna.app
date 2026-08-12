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
        DB::table('festival_notification_preferences')->where('type', 'magic_link')->delete();
        DB::table('festival_notification_settings')->where('type', 'magic_link')->delete();
        DB::table('festival_notifications')->where('type', 'magic_link')->delete();
        Schema::dropIfExists('festival_login_tokens');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('festival_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email_normalized');
            $table->char('token_hash', 64)->unique();
            $table->char('request_ip_hash', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'email_normalized', 'created_at'], 'festival_login_email_created_idx');
        });
    }
};
