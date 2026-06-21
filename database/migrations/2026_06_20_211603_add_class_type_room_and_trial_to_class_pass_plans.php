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
        Schema::table('class_pass_plans', function (Blueprint $table) {
            $table->boolean('is_trial')->default(false)->after('allows_any_time');
            $table->index(['account_id', 'is_active', 'is_trial'], 'class_pass_plans_account_active_trial_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_pass_plans', function (Blueprint $table) {
            $table->dropIndex('class_pass_plans_account_active_trial_index');
            $table->dropColumn('is_trial');
        });
    }
};
