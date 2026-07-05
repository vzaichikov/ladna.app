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
        Schema::table('rooms', function (Blueprint $table) {
            $table->json('people_counter_mask_polygons')->nullable()->after('rtsp_enabled');
            $table->string('people_counter_snapshot_path')->nullable()->after('people_counter_mask_polygons');
            $table->unsignedSmallInteger('people_counter_snapshot_width')->nullable()->after('people_counter_snapshot_path');
            $table->unsignedSmallInteger('people_counter_snapshot_height')->nullable()->after('people_counter_snapshot_width');
            $table->timestamp('people_counter_snapshot_taken_at')->nullable()->after('people_counter_snapshot_height');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn([
                'people_counter_mask_polygons',
                'people_counter_snapshot_path',
                'people_counter_snapshot_width',
                'people_counter_snapshot_height',
                'people_counter_snapshot_taken_at',
            ]);
        });
    }
};
