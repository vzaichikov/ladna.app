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
        Schema::create('scheduled_class_people_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_class_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->index();
            $table->unsignedSmallInteger('attended_count')->default(0);
            $table->unsignedSmallInteger('detected_count')->nullable();
            $table->smallInteger('delta')->nullable();
            $table->unsignedSmallInteger('successful_samples_count')->default(0);
            $table->unsignedSmallInteger('failed_samples_count')->default(0);
            $table->timestamp('first_sampled_at')->nullable();
            $table->timestamp('last_sampled_at')->nullable();
            $table->timestamp('summarized_at')->nullable()->index();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'summarized_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_class_people_counts');
    }
};
