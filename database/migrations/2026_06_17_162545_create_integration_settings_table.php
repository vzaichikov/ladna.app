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
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('category');
            $table->boolean('is_enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'provider']);
            $table->index(['scope_type', 'scope_id', 'category']);
            $table->index(['provider', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_settings');
    }
};
