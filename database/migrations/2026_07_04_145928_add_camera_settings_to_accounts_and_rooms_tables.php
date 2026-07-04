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
            $table->boolean('allow_rtsp_cameras')->default(false)->after('allow_guest_public_booking');
            $table->boolean('enable_people_counter')->default(false)->after('allow_rtsp_cameras');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->text('rtsp_url')->nullable()->after('is_active');
            $table->boolean('rtsp_enabled')->default(false)->after('rtsp_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['rtsp_url', 'rtsp_enabled']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['allow_rtsp_cameras', 'enable_people_counter']);
        });
    }
};
