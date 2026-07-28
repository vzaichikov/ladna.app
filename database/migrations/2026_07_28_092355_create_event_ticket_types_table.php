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
        Schema::create('event_ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('inventory');
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->unsignedBigInteger('early_bird_price_cents')->nullable();
            $table->dateTime('early_bird_ends_at')->nullable();
            $table->unsignedInteger('early_bird_quota')->nullable();
            $table->dateTime('sales_starts_at')->nullable();
            $table->dateTime('sales_ends_at')->nullable();
            $table->unsignedInteger('max_per_order')->default(10);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_ticket_types');
    }
};
