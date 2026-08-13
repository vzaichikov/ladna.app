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
        Schema::table('festival_admission_types', function (Blueprint $table) {
            $table->string('delivery_mode')->default('venue')->after('festival_edition_id');
            $table->foreignId('festival_online_stream_id')->nullable()->after('delivery_mode')->constrained()->restrictOnDelete();
            $table->index(['festival_edition_id', 'delivery_mode', 'is_active'], 'festival_admission_types_delivery_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_admission_types', function (Blueprint $table) {
            $table->dropIndex('festival_admission_types_delivery_idx');
            $table->dropConstrainedForeignId('festival_online_stream_id');
            $table->dropColumn('delivery_mode');
        });
    }
};
