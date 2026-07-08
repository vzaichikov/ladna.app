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
        Schema::create('trainer_private_timeframes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();

            $table->unique(
                ['account_id', 'trainer_id', 'location_id', 'starts_at'],
                'trainer_private_timeframes_unique_slot'
            );
            $table->index(
                ['account_id', 'trainer_id', 'location_id', 'starts_at'],
                'trainer_private_timeframes_lookup_index'
            );
            $table->index(
                ['account_id', 'location_id', 'starts_at'],
                'trainer_private_timeframes_location_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trainer_private_timeframes');
    }
};
