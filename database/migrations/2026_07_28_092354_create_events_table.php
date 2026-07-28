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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('slug');
            $table->string('status')->default('draft')->index();
            $table->string('title');
            $table->string('summary', 500)->nullable();
            $table->longText('description_html')->nullable();
            $table->longText('rules_html')->nullable();
            $table->string('venue_kind')->default('studio');
            $table->string('external_venue_name')->nullable();
            $table->string('external_address')->nullable();
            $table->string('external_map_url', 2048)->nullable();
            $table->text('external_directions')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->index();
            $table->string('timezone', 64);
            $table->char('currency', 3)->default('UAH');
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'slug']);
            $table->index(['account_id', 'status', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
