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
        Schema::create('festival_payment_attempt_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_payment_attempt_id')->constrained(indexName: 'festival_attempt_allocation_attempt_fk')->cascadeOnDelete();
            $table->foreignId('festival_charge_id')->constrained(indexName: 'festival_attempt_allocation_charge_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->timestamps();
            $table->unique(['festival_payment_attempt_id', 'festival_charge_id'], 'festival_payment_attempt_charge_unique');
            $table->index(['festival_charge_id', 'festival_payment_attempt_id'], 'festival_charge_payment_attempt_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_payment_attempt_charges');
    }
};
