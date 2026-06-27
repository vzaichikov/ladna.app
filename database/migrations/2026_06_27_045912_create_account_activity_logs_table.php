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
        Schema::create('account_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('action', 180);
            $table->string('route_name', 180)->nullable();
            $table->string('method', 10);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_trainer_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['account_id', 'occurred_at'], 'activity_logs_account_time_index');
            $table->index(['account_id', 'action', 'occurred_at'], 'activity_logs_action_index');
            $table->index(['account_id', 'actor_user_id', 'occurred_at'], 'activity_logs_actor_index');
            $table->index(['subject_type', 'subject_id'], 'activity_logs_subject_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_activity_logs');
    }
};
