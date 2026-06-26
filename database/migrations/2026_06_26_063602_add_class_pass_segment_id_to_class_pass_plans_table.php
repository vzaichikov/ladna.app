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
            $table->foreignId('class_pass_segment_id')
                ->nullable()
                ->after('schedule_kind')
                ->constrained()
                ->nullOnDelete();
            $table->index(['account_id', 'schedule_kind', 'class_pass_segment_id', 'is_active', 'sort_order'], 'class_pass_plans_account_kind_segment_active_sort_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_pass_plans', function (Blueprint $table) {
            $table->dropIndex('class_pass_plans_account_kind_segment_active_sort_index');
            $table->dropConstrainedForeignId('class_pass_segment_id');
        });
    }
};
