<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('festival_directions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['festival_edition_id', 'code'], 'festival_direction_code_unique');
            $table->index(['festival_edition_id', 'is_active', 'sort_order'], 'festival_directions_active_idx');
        });

        Schema::table('festival_categories', function (Blueprint $table): void {
            $table->foreignId('festival_direction_id')
                ->nullable()
                ->after('festival_workflow_id')
                ->constrained()
                ->restrictOnDelete();
            $table->longText('requirements_html')->nullable()->after('registration_closes_at');
        });
    }

    /**
     * This expand migration is part of an intentionally forward-only sequence.
     */
    public function down(): void {}
};
