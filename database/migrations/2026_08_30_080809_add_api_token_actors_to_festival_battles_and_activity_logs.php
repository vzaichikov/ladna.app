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
        Schema::table('festival_battle_matches', function (Blueprint $table): void {
            $table->foreignId('decided_by_account_api_token_id')
                ->nullable()
                ->after('decided_by')
                ->constrained('account_api_tokens')
                ->nullOnDelete();
        });

        Schema::table('festival_activity_logs', function (Blueprint $table): void {
            $table->foreignId('actor_account_api_token_id')
                ->nullable()
                ->after('actor_portal_user_id')
                ->constrained('account_api_tokens')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_activity_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_account_api_token_id');
        });

        Schema::table('festival_battle_matches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('decided_by_account_api_token_id');
        });
    }
};
