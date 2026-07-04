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
        Schema::table('class_bookings', function (Blueprint $table) {
            $table->timestamp('corrected_removed_at')->nullable()->after('skip_class_pass_reservation')->index();
            $table->unsignedBigInteger('corrected_removed_by_user_id')->nullable()->after('corrected_removed_at');
            $table->index(['scheduled_class_id', 'corrected_removed_at'], 'class_bookings_class_correction_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_bookings', function (Blueprint $table) {
            $table->dropIndex('class_bookings_class_correction_index');
            $table->dropIndex(['corrected_removed_at']);
            $table->dropColumn(['corrected_removed_at', 'corrected_removed_by_user_id']);
        });
    }
};
