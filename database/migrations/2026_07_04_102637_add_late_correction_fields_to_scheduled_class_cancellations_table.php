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
        Schema::table('scheduled_class_cancellations', function (Blueprint $table) {
            $table->string('cancellation_mode')->default('standard')->after('previous_scheduled_class_status');
            $table->string('pass_effect')->nullable()->after('cancellation_mode');
            $table->text('reason')->nullable()->after('pass_effect');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_class_cancellations', function (Blueprint $table) {
            $table->dropColumn(['cancellation_mode', 'pass_effect', 'reason']);
        });
    }
};
