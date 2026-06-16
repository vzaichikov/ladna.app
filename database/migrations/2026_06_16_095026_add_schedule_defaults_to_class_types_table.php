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
            $table->foreignId('activity_direction_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->string('schedule_kind')->default('group_class')->after('color');
            $table->unsignedSmallInteger('default_duration_minutes')->default(60)->after('schedule_kind');
            $table->unsignedSmallInteger('booking_cutoff_minutes')->nullable()->after('default_duration_minutes');
            $table->unsignedSmallInteger('default_capacity')->nullable()->after('booking_cutoff_minutes');
            $table->index(['account_id', 'schedule_kind', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_types', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'schedule_kind', 'is_active']);
            $table->dropConstrainedForeignId('activity_direction_id');
            $table->dropColumn([
                'schedule_kind',
                'default_duration_minutes',
                'booking_cutoff_minutes',
                'default_capacity',
            ]);
        });
    }
};
