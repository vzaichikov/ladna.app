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
        Schema::create('unknown_presence_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->index();
            $table->unsignedSmallInteger('sample_count')->default(0);
            $table->unsignedSmallInteger('peak_detected_count')->default(0);
            $table->timestamps();

            $table->index(['account_id', 'started_at']);
            $table->index(['account_id', 'ended_at']);
            $table->index(['room_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unknown_presence_intervals');
    }
};
