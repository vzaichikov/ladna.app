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
        Schema::table('class_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('cancellation_cutoff_minutes')->nullable()->default(1440)->after('booking_cutoff_minutes');
        });

        Schema::table('schedule_series', function (Blueprint $table) {
            $table->unsignedSmallInteger('cancellation_cutoff_minutes')->nullable()->after('booking_cutoff_minutes');
        });

        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->unsignedSmallInteger('cancellation_cutoff_minutes')->nullable()->after('booking_cutoff_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->dropColumn('cancellation_cutoff_minutes');
        });

        Schema::table('schedule_series', function (Blueprint $table) {
            $table->dropColumn('cancellation_cutoff_minutes');
        });

        Schema::table('class_types', function (Blueprint $table) {
            $table->dropColumn('cancellation_cutoff_minutes');
        });
    }
};
