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
        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->index(['account_id', 'starts_at'], 'scheduled_classes_account_starts_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->dropIndex('scheduled_classes_account_starts_at_index');
        });
    }
};
