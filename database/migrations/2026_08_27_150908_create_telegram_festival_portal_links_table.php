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
        Schema::create('telegram_festival_portal_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('telegram_chat_authorization_id');
            $table->foreignId('festival_portal_user_id');
            $table->timestamps();

            $table->unique(
                ['telegram_chat_authorization_id', 'festival_portal_user_id'],
                'telegram_festival_portal_links_auth_user_unique',
            );
            $table->index(
                ['account_id', 'telegram_chat_authorization_id'],
                'telegram_festival_portal_links_account_auth_idx',
            );
            $table->index(
                ['festival_portal_user_id', 'telegram_chat_authorization_id'],
                'telegram_festival_portal_links_user_auth_idx',
            );
            $table->foreign('account_id', 'tg_festival_links_account_fk')
                ->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('telegram_chat_authorization_id', 'tg_festival_links_auth_fk')
                ->references('id')->on('telegram_chat_authorizations')->cascadeOnDelete();
            $table->foreign('festival_portal_user_id', 'tg_festival_links_portal_user_fk')
                ->references('id')->on('festival_portal_users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_festival_portal_links');
    }
};
