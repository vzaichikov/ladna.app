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
        Schema::create('subscription_plan_sms_rate_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_plan_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('old_sms_segment_price_cents')->nullable();
            $table->unsignedInteger('new_sms_segment_price_cents')->nullable();
            $table->timestamps();

            $table->index(
                ['subscription_plan_id', 'created_at'],
                'plan_sms_rate_changes_plan_created_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_sms_rate_changes');
    }
};
