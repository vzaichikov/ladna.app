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
        Schema::create('customer_class_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_pass_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 16);
            $table->string('source')->default('manual');
            $table->string('status')->default('active');
            $table->string('plan_name');
            $table->string('plan_slug')->nullable();
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3)->default('UAH');
            $table->unsignedSmallInteger('sessions_count');
            $table->unsignedSmallInteger('validity_days');
            $table->unsignedSmallInteger('reserved_sessions_count')->default(0);
            $table->unsignedSmallInteger('used_sessions_count')->default(0);
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code');
            $table->unique(['account_id', 'code']);
            $table->index(['account_id', 'is_active', 'status', 'purchased_at'], 'customer_class_passes_account_active_status_index');
            $table->index(['customer_id', 'is_active', 'purchased_at'], 'customer_class_passes_customer_active_index');
            $table->index(['class_pass_plan_id', 'status'], 'customer_class_passes_plan_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_class_passes');
    }
};
