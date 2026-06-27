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
        $columns = [
            'capacity',
            'duration_minutes',
            'booking_cutoff_minutes',
            'cancellation_cutoff_minutes',
        ];
        $missingColumns = array_filter($columns, fn (string $column): bool => ! Schema::hasColumn('schedule_series', $column));

        if (count($missingColumns) === count($columns)) {
            return;
        }

        if ($missingColumns !== []) {
            throw new RuntimeException('Cannot remove schedule_series override columns because the schema is partially migrated.');
        }

        $divergentOverrides = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS aggregate
            FROM schedule_series s
            INNER JOIN class_types ct ON ct.id = s.class_type_id
            INNER JOIN rooms r ON r.id = s.room_id
            WHERE (s.duration_minutes IS NOT NULL AND s.duration_minutes <> ct.default_duration_minutes)
                OR (s.booking_cutoff_minutes IS NOT NULL AND NOT (s.booking_cutoff_minutes <=> ct.booking_cutoff_minutes))
                OR (s.cancellation_cutoff_minutes IS NOT NULL AND NOT (s.cancellation_cutoff_minutes <=> ct.cancellation_cutoff_minutes))
                OR (s.capacity IS NOT NULL AND NOT (s.capacity <=> COALESCE(ct.default_capacity, r.capacity)))
            SQL);

        if ((int) ($divergentOverrides->aggregate ?? 0) > 0) {
            throw new RuntimeException('Cannot remove schedule_series override columns while divergent recurring schedule settings exist.');
        }

        Schema::table('schedule_series', function (Blueprint $table) {
            $table->dropColumn(['capacity', 'duration_minutes', 'booking_cutoff_minutes', 'cancellation_cutoff_minutes']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_series', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->nullable()->after('end_date');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('capacity');
            $table->unsignedSmallInteger('booking_cutoff_minutes')->nullable()->after('duration_minutes');
            $table->unsignedSmallInteger('cancellation_cutoff_minutes')->nullable()->after('booking_cutoff_minutes');
        });
    }
};
