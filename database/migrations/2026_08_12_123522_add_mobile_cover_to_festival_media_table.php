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
        Schema::table('festival_media', function (Blueprint $table) {
            $table->boolean('is_mobile_cover')->default(false)->after('is_cover');
            $table->index(['festival_edition_id', 'is_mobile_cover'], 'festival_media_mobile_cover_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_media', function (Blueprint $table) {
            $table->dropIndex('festival_media_mobile_cover_idx');
            $table->dropColumn('is_mobile_cover');
        });
    }
};
