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
        Schema::create('sms_wallet_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('account_sms_wallet_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('type');
            $table->bigInteger('amount_cents');
            $table->unsignedBigInteger('balance_after_cents');
            $table->unsignedBigInteger('outstanding_after_cents')->default(0);
            $table->string('reference_type', 191)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamps();

            $table->index(
                ['account_id', 'created_at'],
                'sms_ledger_account_created_idx',
            );
            $table->index(
                ['account_sms_wallet_id', 'created_at'],
                'sms_ledger_wallet_created_idx',
            );
            $table->index(
                ['type', 'created_at'],
                'sms_ledger_type_created_idx',
            );
            $table->index(
                ['reference_type', 'reference_id'],
                'sms_ledger_reference_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_wallet_ledger_entries');
    }
};
