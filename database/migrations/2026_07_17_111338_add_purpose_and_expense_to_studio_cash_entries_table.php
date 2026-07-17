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
        Schema::table('studio_cash_entries', function (Blueprint $table) {
            $table->foreignId('studio_expense_id')
                ->nullable()
                ->after('location_id')
                ->constrained('studio_expenses')
                ->cascadeOnDelete();
            $table->string('purpose')->default('deposit')->after('direction');

            $table->index(['account_id', 'purpose', 'occurred_at'], 'studio_cash_entries_purpose_index');
            $table->unique(['studio_expense_id', 'purpose'], 'studio_cash_entries_expense_purpose_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studio_cash_entries', function (Blueprint $table) {
            $table->dropForeign(['studio_expense_id']);
        });

        Schema::table('studio_cash_entries', function (Blueprint $table) {
            $table->dropUnique('studio_cash_entries_expense_purpose_unique');
            $table->dropIndex('studio_cash_entries_purpose_index');
        });

        Schema::table('studio_cash_entries', function (Blueprint $table) {
            $table->dropColumn('studio_expense_id');
            $table->dropColumn('purpose');
        });
    }
};
