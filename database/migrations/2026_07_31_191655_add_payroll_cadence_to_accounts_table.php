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
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('payroll_cadence', 32)->default('monthly')->after('trainer_private_timeframe_weeks');
            $table->date('payroll_anchor_date')->nullable()->after('payroll_cadence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['payroll_cadence', 'payroll_anchor_date']);
        });
    }
};
