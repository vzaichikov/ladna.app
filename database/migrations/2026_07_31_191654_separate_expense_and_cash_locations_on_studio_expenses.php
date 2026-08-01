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
        Schema::table('studio_expenses', function (Blueprint $table) {
            $table->foreignId('expense_location_id')->nullable()->after('location_id')->constrained('locations')->nullOnDelete();
            $table->foreignId('cash_location_id')->nullable()->after('expense_location_id')->constrained('locations')->restrictOnDelete();
            $table->index(['account_id', 'expense_location_id', 'occurred_at'], 'studio_expenses_location_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studio_expenses', function (Blueprint $table) {
            $table->dropIndex('studio_expenses_location_time_idx');
            $table->dropConstrainedForeignId('cash_location_id');
            $table->dropConstrainedForeignId('expense_location_id');
        });
    }
};
