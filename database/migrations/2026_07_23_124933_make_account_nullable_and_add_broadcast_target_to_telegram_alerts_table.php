<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_alerts', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });

        Schema::table('telegram_alerts', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->change();
            $table->foreignId('telegram_broadcast_target_id')
                ->nullable()
                ->after('telegram_chat_authorization_id')
                ->constrained()
                ->nullOnDelete();
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_alerts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('telegram_broadcast_target_id');
            $table->dropForeign(['account_id']);
        });

        Schema::table('telegram_alerts', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable(false)->change();
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();
        });
    }
};
