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
        Schema::table('festival_ticket_orders', function (Blueprint $table) {
            $table->foreignId('purchaser_festival_portal_user_id')
                ->nullable()
                ->after('festival_portal_user_id')
                ->constrained('festival_portal_users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_ticket_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchaser_festival_portal_user_id');
        });
    }
};
