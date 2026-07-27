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
        Schema::create('email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scenario');
            $table->string('status')->default('pending');
            $table->string('recipient_kind');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email');
            $table->string('locale', 5);
            $table->string('account_timezone')->nullable();
            $table->string('subject')->nullable();
            $table->string('subject_key');
            $table->json('subject_parameters')->nullable();
            $table->string('content_view');
            $table->json('payload');
            $table->longText('html_body')->nullable();
            $table->longText('text_body')->nullable();
            $table->string('configured_engine')->nullable();
            $table->string('actual_engine')->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->string('provider_message_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('status_reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at', 'id'], 'email_deliveries_status_created_index');
            $table->index(['scenario', 'status', 'created_at'], 'email_deliveries_scenario_status_index');
            $table->index(['account_id', 'scenario', 'created_at'], 'email_deliveries_account_scenario_index');
            $table->index(['actual_engine', 'status', 'created_at'], 'email_deliveries_engine_status_index');
            $table->index(['recipient_email', 'created_at'], 'email_deliveries_recipient_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
    }
};
