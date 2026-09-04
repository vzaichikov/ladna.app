<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->string('discount_type', 16);
            $table->unsignedBigInteger('discount_value');
            $table->char('currency', 3);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('max_total_uses')->nullable();
            $table->unsignedInteger('max_uses_per_identity')->nullable()->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['event_id', 'code']);
            $table->index(['account_id', 'is_active']);
            $table->index(['event_id', 'is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_promo_codes');
    }
};
