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
        Schema::create('people_counter_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_class_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('captured_at')->index();
            $table->string('status', 40)->index();
            $table->text('failure_reason')->nullable();
            $table->string('original_image_path')->nullable();
            $table->string('masked_image_path')->nullable();
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->unsignedSmallInteger('detected_count')->nullable();
            $table->decimal('average_confidence', 5, 4)->nullable();
            $table->json('detections')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'captured_at']);
            $table->index(['scheduled_class_id', 'status']);
            $table->index(['room_id', 'captured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('people_counter_samples');
    }
};
