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
        Schema::table('festival_entries', function (Blueprint $table): void {
            $table->string('track_artist')->nullable()->after('act_description');
            $table->string('track_title')->nullable()->after('track_artist');
            $table->string('normalized_track_key', 64)->nullable()->after('track_title');
            $table->timestamp('track_reserved_at')->nullable()->after('normalized_track_key');
            $table->unique(['festival_category_id', 'normalized_track_key'], 'festival_entries_category_track_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_entries', function (Blueprint $table): void {
            $table->dropUnique('festival_entries_category_track_unique');
            $table->dropColumn(['track_artist', 'track_title', 'normalized_track_key', 'track_reserved_at']);
        });
    }
};
