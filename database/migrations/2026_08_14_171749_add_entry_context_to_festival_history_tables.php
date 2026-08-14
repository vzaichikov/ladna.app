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
        Schema::table('festival_activity_logs', function (Blueprint $table): void {
            $table->foreignId('festival_entry_id')
                ->nullable()
                ->after('festival_edition_id')
                ->constrained()
                ->nullOnDelete();
            $table->index(
                ['festival_entry_id', 'occurred_at', 'id'],
                'festival_activity_entry_history_idx',
            );
        });

        Schema::table('festival_notifications', function (Blueprint $table): void {
            $table->index(
                ['festival_portal_user_id', 'id'],
                'festival_notifications_portal_latest_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_notifications', function (Blueprint $table): void {
            $table->dropIndex('festival_notifications_portal_latest_idx');
        });

        Schema::table('festival_activity_logs', function (Blueprint $table): void {
            $table->dropIndex('festival_activity_entry_history_idx');
            $table->dropConstrainedForeignId('festival_entry_id');
        });
    }
};
