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
        Schema::table('people_counter_samples', function (Blueprint $table) {
            $table->foreignId('unknown_presence_interval_id')
                ->nullable()
                ->after('scheduled_class_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['unknown_presence_interval_id', 'captured_at'], 'people_counter_samples_unknown_interval_captured_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('people_counter_samples', function (Blueprint $table) {
            $table->dropIndex('people_counter_samples_unknown_interval_captured_index');
            $table->dropConstrainedForeignId('unknown_presence_interval_id');
        });
    }
};
