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
        Schema::create('customer_purchase_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('new_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedInteger('previous_amount_cents');
            $table->unsignedInteger('new_amount_cents');
            $table->timestamp('previous_paid_at')->nullable();
            $table->timestamp('new_paid_at')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_trainer_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->text('reason');
            $table->timestamps();
            $table->index(['account_id', 'customer_purchase_id'], 'purchase_corrections_payment_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_purchase_corrections');
    }
};
