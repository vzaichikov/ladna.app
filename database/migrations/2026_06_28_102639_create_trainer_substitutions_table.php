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
        Schema::create('trainer_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('replaced_trainer_id')->constrained('trainers')->cascadeOnDelete();
            $table->foreignId('substitute_trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 20);
            $table->date('date_from');
            $table->date('date_to');
            $table->json('scheduled_class_ids')->nullable();
            $table->json('class_type_ids')->nullable();
            $table->string('replaced_trainer_name')->nullable();
            $table->string('substitute_trainer_name')->nullable();
            $table->string('location_name')->nullable();
            $table->string('room_name')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'replaced_trainer_id', 'mode', 'created_at'], 'trainer_subs_account_replaced_mode_index');
            $table->index(['account_id', 'date_from', 'date_to'], 'trainer_subs_account_dates_index');
            $table->index(['account_id', 'location_id', 'room_id'], 'trainer_subs_account_room_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trainer_substitutions');
    }
};
