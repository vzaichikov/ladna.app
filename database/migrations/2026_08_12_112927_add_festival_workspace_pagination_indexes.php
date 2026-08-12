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
        Schema::table('festival_tickets', function (Blueprint $table): void {
            $table->index(['festival_edition_id', 'id'], 'festival_tickets_edition_latest_idx');
        });
        Schema::table('festival_announcements', function (Blueprint $table): void {
            $table->index(['festival_edition_id', 'id'], 'festival_announcements_edition_latest_idx');
        });
        Schema::table('festival_notifications', function (Blueprint $table): void {
            $table->index(['festival_edition_id', 'id'], 'festival_notifications_edition_latest_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_tickets', function (Blueprint $table): void {
            $table->dropIndex('festival_tickets_edition_latest_idx');
        });
        Schema::table('festival_announcements', function (Blueprint $table): void {
            $table->dropIndex('festival_announcements_edition_latest_idx');
        });
        Schema::table('festival_notifications', function (Blueprint $table): void {
            $table->dropIndex('festival_notifications_edition_latest_idx');
        });
    }
};
