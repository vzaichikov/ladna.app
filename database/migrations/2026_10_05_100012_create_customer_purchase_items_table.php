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
        Schema::create('customer_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_pass_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_class_pass_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('plan_name');
            $table->string('plan_slug')->nullable();
            $table->string('schedule_kind');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('sessions_count');
            $table->unsignedSmallInteger('validity_days');
            $table->unsignedSmallInteger('total_validity_days');
            $table->time('available_from_time')->nullable();
            $table->time('available_until_time')->nullable();
            $table->boolean('allows_any_time')->default(false);
            $table->unsignedInteger('any_time_addon_price_cents')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->unique(['customer_purchase_id', 'position'], 'purchase_item_position_unique');
            $table->unique('customer_class_pass_id', 'purchase_item_issued_pass_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_purchase_items');
    }
};
