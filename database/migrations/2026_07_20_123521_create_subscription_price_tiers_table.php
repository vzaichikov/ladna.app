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
        Schema::create('subscription_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_price_version_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedInteger('starts_at_location');
            $table->unsignedInteger('ends_at_location')->nullable();
            $table->unsignedInteger('unit_price_cents');
            $table->timestamps();

            $table->unique(
                ['subscription_price_version_id', 'starts_at_location'],
                'subscription_price_tiers_version_start_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_price_tiers');
    }
};
