<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable()->after('closed_at');
        });

        Schema::table('customer_class_pass_adjustments', function (Blueprint $table) {
            $table->string('adjustment_type')->default('sessions')->after('actor_role');
            $table->smallInteger('sessions_delta')->nullable()->change();
            $table->unsignedSmallInteger('previous_sessions_count')->nullable()->change();
            $table->unsignedSmallInteger('new_sessions_count')->nullable()->change();
            $table->smallInteger('days_delta')->nullable()->after('new_sessions_count');
            $table->unsignedSmallInteger('previous_validity_days')->nullable()->after('days_delta');
            $table->unsignedSmallInteger('new_validity_days')->nullable()->after('previous_validity_days');
            $table->string('previous_status')->nullable()->after('new_validity_days');
            $table->string('new_status')->nullable()->after('previous_status');
            $table->timestamp('freeze_started_at')->nullable()->after('new_status');
            $table->timestamp('freeze_finished_at')->nullable()->after('freeze_started_at');
            $table->unsignedSmallInteger('freeze_days_count')->nullable()->after('freeze_finished_at');

            $table->index(['account_id', 'adjustment_type'], 'pass_adjustments_account_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_class_pass_adjustments', function (Blueprint $table) {
            $table->dropIndex('pass_adjustments_account_type_index');
            $table->dropColumn([
                'adjustment_type',
                'days_delta',
                'previous_validity_days',
                'new_validity_days',
                'previous_status',
                'new_status',
                'freeze_started_at',
                'freeze_finished_at',
                'freeze_days_count',
            ]);
        });

        DB::table('customer_class_pass_adjustments')
            ->whereNull('sessions_delta')
            ->update([
                'sessions_delta' => 0,
                'previous_sessions_count' => 0,
                'new_sessions_count' => 0,
            ]);

        Schema::table('customer_class_pass_adjustments', function (Blueprint $table) {
            $table->smallInteger('sessions_delta')->nullable(false)->change();
            $table->unsignedSmallInteger('previous_sessions_count')->nullable(false)->change();
            $table->unsignedSmallInteger('new_sessions_count')->nullable(false)->change();
        });

        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->dropColumn('frozen_at');
        });
    }
};
