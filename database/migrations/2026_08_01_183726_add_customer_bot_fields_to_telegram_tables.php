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
        Schema::table('telegram_bot_installations', function (Blueprint $table): void {
            $table->string('bot_id')->nullable()->unique()->after('profile');
        });

        Schema::table('telegram_chat_authorizations', function (Blueprint $table): void {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('trainer_id')
                ->constrained()
                ->nullOnDelete();
            $table->index(['customer_id', 'status'], 'telegram_chat_authorizations_customer_status_index');
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->timestamp('available_at')->nullable()->after('attempts');
            $table->timestamp('processing_started_at')->nullable()->after('available_at');
            $table->index(['status', 'available_at', 'id'], 'telegram_updates_retry_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropIndex('telegram_updates_retry_lookup_index');
            $table->dropColumn(['attempts', 'available_at', 'processing_started_at']);
        });

        Schema::table('telegram_chat_authorizations', function (Blueprint $table): void {
            $table->dropIndex('telegram_chat_authorizations_customer_status_index');
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('telegram_bot_installations', function (Blueprint $table): void {
            $table->dropUnique(['bot_id']);
            $table->dropColumn('bot_id');
        });
    }
};
