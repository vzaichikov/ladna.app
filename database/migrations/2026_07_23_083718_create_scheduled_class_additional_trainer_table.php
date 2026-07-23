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
        Schema::create('scheduled_class_additional_trainer', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['scheduled_class_id', 'trainer_id'],
                'scheduled_class_additional_trainer_unique',
            );
            $table->index(
                ['account_id', 'trainer_id'],
                'scheduled_class_additional_trainer_lookup',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_class_additional_trainer');
    }
};
