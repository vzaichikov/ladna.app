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
        Schema::create('sms_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('account_sms_wallet_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->string('source_type', 191)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('purpose');
            $table->string('source_mode');
            $table->string('provider')->nullable();
            $table->string('status')->default('pending');
            $table->string('recipient_phone');
            $table->string('message_preview')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->unsignedSmallInteger('estimated_segments')->default(1);
            $table->unsignedSmallInteger('provider_segments')->nullable();
            $table->unsignedSmallInteger('billed_segments')->nullable();
            $table->unsignedInteger('sms_segment_price_cents')->nullable();
            $table->unsignedBigInteger('reserved_amount_cents')->default(0);
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->unsignedBigInteger('wholesale_cost_cents')->nullable();
            $table->char('currency', 3)->default('UAH');
            $table->string('provider_message_id')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_status_checked_at')->nullable();
            $table->timestamp('next_status_check_at')->nullable();
            $table->timestamp('status_polling_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(
                ['account_id', 'status', 'created_at'],
                'sms_deliveries_account_status_created_idx',
            );
            $table->index(
                ['purpose', 'created_at'],
                'sms_deliveries_purpose_created_idx',
            );
            $table->index(
                ['source_mode', 'created_at'],
                'sms_deliveries_mode_created_idx',
            );
            $table->index(
                ['provider', 'provider_message_id'],
                'sms_deliveries_provider_message_idx',
            );
            $table->index(
                ['status', 'next_status_check_at'],
                'sms_deliveries_status_poll_idx',
            );
            $table->index(
                ['source_type', 'source_id'],
                'sms_deliveries_source_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_deliveries');
    }
};
