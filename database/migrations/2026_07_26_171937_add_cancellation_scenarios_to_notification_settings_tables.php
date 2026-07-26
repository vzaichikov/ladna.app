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
        Schema::table('trainer_notification_settings', function (Blueprint $table) {
            $table->boolean('class_cancellation_enabled')
                ->default(false)
                ->after('trainer_assignment_enabled');
        });

        Schema::table('customer_notification_settings', function (Blueprint $table) {
            $table->boolean('class_cancellation_enabled')
                ->default(false)
                ->after('class_reminder_hours_before');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trainer_notification_settings', function (Blueprint $table) {
            $table->dropColumn('class_cancellation_enabled');
        });

        Schema::table('customer_notification_settings', function (Blueprint $table) {
            $table->dropColumn('class_cancellation_enabled');
        });
    }
};
