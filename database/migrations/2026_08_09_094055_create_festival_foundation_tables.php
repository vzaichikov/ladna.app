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
        Schema::create('festival_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('summary', 500)->nullable();
            $table->string('organizer_name')->nullable();
            $table->string('organizer_email')->nullable();
            $table->string('organizer_phone')->nullable();
            $table->string('organizer_telegram_url', 2048)->nullable();
            $table->string('organizer_instagram_url', 2048)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 20)->nullable();
            $table->json('defaults')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['account_id', 'slug']);
        });

        Schema::create('festival_editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_series_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->string('status')->default('draft')->index();
            $table->string('registration_status')->default('closed')->index();
            $table->string('summary', 500)->nullable();
            $table->longText('description_html')->nullable();
            $table->longText('rules_html')->nullable();
            $table->string('venue_name')->nullable();
            $table->string('venue_address', 500)->nullable();
            $table->string('venue_map_url', 2048)->nullable();
            $table->text('venue_directions')->nullable();
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();
            $table->date('age_reference_date');
            $table->timestamp('registration_opens_at')->nullable()->index();
            $table->timestamp('registration_closes_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'slug']);
            $table->index(['account_id', 'status', 'starts_at']);
        });

        Schema::create('festival_content_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('title');
            $table->string('kind')->default('rich_text');
            $table->string('visibility')->default('public');
            $table->longText('body_html')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['festival_edition_id', 'key']);
            $table->index(['festival_edition_id', 'visibility', 'sort_order'], 'festival_sections_visibility_idx');
        });

        Schema::create('festival_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('kind')->default('document');
            $table->string('visibility')->default('public');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['festival_edition_id', 'visibility', 'sort_order'], 'festival_documents_visibility_idx');
        });

        Schema::create('festival_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->string('alt_text')->nullable();
            $table->string('caption', 500)->nullable();
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['festival_edition_id', 'kind', 'sort_order'], 'festival_media_kind_idx');
        });

        Schema::create('festival_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['festival_edition_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_stages');
        Schema::dropIfExists('festival_media');
        Schema::dropIfExists('festival_documents');
        Schema::dropIfExists('festival_content_sections');
        Schema::dropIfExists('festival_editions');
        Schema::dropIfExists('festival_series');
    }
};
