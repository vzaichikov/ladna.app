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
        Schema::create('studio_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->string('discount_type', 16);
            $table->unsignedInteger('discount_value');
            $table->char('currency', 3)->default('UAH');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('max_total_uses')->nullable();
            $table->unsignedInteger('max_uses_per_identity')->nullable()->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'code']);
            $table->index(['account_id', 'is_active', 'starts_at', 'ends_at'], 'studio_promo_codes_active_window_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('studio_promo_codes');
    }
};
