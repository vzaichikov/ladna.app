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
        Schema::create('studio_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('UAH');
            $table->string('payment_method');
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_trainer_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->text('reason');
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('voided_by_actor_user_id')->nullable();
            $table->unsignedBigInteger('voided_by_actor_trainer_id')->nullable();
            $table->string('voided_by_actor_name')->nullable();
            $table->string('voided_by_actor_email')->nullable();
            $table->string('voided_by_actor_role')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'occurred_at'], 'studio_expenses_account_time_index');
            $table->index(['account_id', 'expense_category_id', 'occurred_at'], 'studio_expenses_category_time_index');
            $table->index(['account_id', 'payment_method', 'occurred_at'], 'studio_expenses_method_time_index');
            $table->index(['account_id', 'voided_at', 'occurred_at'], 'studio_expenses_status_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('studio_expenses');
    }
};
