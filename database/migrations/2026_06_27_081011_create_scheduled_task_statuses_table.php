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
        Schema::create('scheduled_task_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('task_key')->unique();
            $table->string('command');
            $table->string('expression', 100);
            $table->string('status', 40)->default('never_run');
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->unsignedSmallInteger('last_exit_code')->nullable();
            $table->text('last_output')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_started_at'], 'scheduled_task_status_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_statuses');
    }
};
