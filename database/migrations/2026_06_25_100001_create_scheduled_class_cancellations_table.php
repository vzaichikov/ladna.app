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
        Schema::create('scheduled_class_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_scheduled_class_status');
            $table->json('rules_snapshot');
            $table->timestamp('cancelled_at')->index();
            $table->timestamp('restored_at')->nullable()->index();
            $table->timestamps();

            $table->index(['account_id', 'scheduled_class_id', 'restored_at'], 'class_cancellations_account_class_restored_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_class_cancellations');
    }
};
