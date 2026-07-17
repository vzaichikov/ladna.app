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
        Schema::create('scheduled_class_trainer_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_class_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('previous_trainer_id')->nullable();
            $table->unsignedBigInteger('new_trainer_id')->nullable();
            $table->string('previous_trainer_name')->nullable();
            $table->string('new_trainer_name')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_trainer_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->timestamps();

            $table->index(
                ['scheduled_class_id', 'id'],
                'scheduled_class_trainer_changes_lookup_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_class_trainer_changes');
    }
};
