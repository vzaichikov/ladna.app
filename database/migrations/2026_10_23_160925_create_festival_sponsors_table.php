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
        Schema::create('festival_sponsors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 2048)->nullable();
            $table->string('logo_path');
            $table->string('short_description', 500);
            $table->text('giveaway_description')->nullable();
            $table->boolean('show_on_all_pages')->default(false);
            $table->timestamps();

            $table->index(['festival_edition_id', 'show_on_all_pages', 'id'], 'festival_sponsors_edition_visibility_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_sponsors');
    }
};
