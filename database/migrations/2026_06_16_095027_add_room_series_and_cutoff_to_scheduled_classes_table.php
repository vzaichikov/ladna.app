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
        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('location_id')->constrained()->nullOnDelete();
            $table->foreignId('schedule_series_id')->nullable()->after('instructor_id')->constrained('schedule_series')->nullOnDelete();
            $table->unsignedSmallInteger('booking_cutoff_minutes')->nullable()->after('capacity');
            $table->boolean('is_generated')->default(false)->after('booking_cutoff_minutes');
            $table->boolean('is_manually_modified')->default(false)->after('is_generated');
            $table->json('metadata')->nullable()->after('is_manually_modified');
            $table->index(['room_id', 'starts_at']);
            $table->index(['schedule_series_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'starts_at']);
            $table->dropIndex(['schedule_series_id', 'starts_at']);
            $table->dropConstrainedForeignId('room_id');
            $table->dropConstrainedForeignId('schedule_series_id');
            $table->dropColumn([
                'booking_cutoff_minutes',
                'is_generated',
                'is_manually_modified',
                'metadata',
            ]);
        });
    }
};
