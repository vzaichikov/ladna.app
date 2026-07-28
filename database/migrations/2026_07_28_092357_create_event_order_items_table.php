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
        Schema::create('event_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_ticket_type_id')->constrained()->restrictOnDelete();
            $table->string('ticket_type_name');
            $table->text('ticket_type_description')->nullable();
            $table->string('price_tier')->default('regular');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();

            $table->index(['event_ticket_type_id', 'price_tier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_order_items');
    }
};
