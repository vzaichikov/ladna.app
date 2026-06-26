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
        Schema::create('fiscal_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->morphs('payment');
            $table->string('provider');
            $table->string('status')->default('pending');
            $table->string('external_uuid')->nullable();
            $table->string('provider_receipt_id')->nullable();
            $table->string('provider_status')->nullable();
            $table->string('fiscal_number')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('fiscalized_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_type', 'payment_id', 'provider']);
            $table->index(['account_id', 'status']);
            $table->index(['scope_type', 'scope_id', 'status']);
            $table->index(['provider', 'status']);
            $table->index('external_uuid');
            $table->index('fiscalized_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_receipts');
    }
};
