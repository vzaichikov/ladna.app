<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('festival_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->string('discount_type');
            $table->unsignedBigInteger('discount_value');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('total_usage_limit')->nullable();
            $table->unsignedInteger('per_identity_usage_limit')->nullable()->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['festival_edition_id', 'code'], 'festival_promo_codes_edition_code_unique');
            $table->index(['account_id', 'festival_edition_id', 'is_active'], 'festival_promo_codes_scope_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('festival_promo_codes');
    }
};
