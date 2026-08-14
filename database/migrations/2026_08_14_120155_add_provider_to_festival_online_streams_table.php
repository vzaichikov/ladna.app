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
        Schema::table('festival_online_streams', function (Blueprint $table) {
            $table->string('provider')->default('mediamtx')->after('is_enabled');
            $table->string('youtube_video_id', 11)->nullable()->after('publisher_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_online_streams', function (Blueprint $table) {
            $table->dropColumn(['provider', 'youtube_video_id']);
        });
    }
};
