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
        Schema::create('salary_model_class_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_model_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('class_type_name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('formula_type', 40);
            $table->unsignedInteger('flat_amount_cents')->nullable();
            $table->unsignedInteger('person_rate_cents')->nullable();
            $table->unsignedSmallInteger('minimum_people')->default(0);
            $table->unsignedInteger('base_amount_cents')->nullable();
            $table->unsignedSmallInteger('included_people')->default(0);
            $table->unsignedInteger('hourly_rate_cents')->nullable();
            $table->unsignedInteger('extra_person_rate_cents')->nullable();
            $table->unsignedInteger('minimum_pay_cents')->nullable();
            $table->unsignedInteger('maximum_pay_cents')->nullable();
            $table->timestamps();

            $table->index(
                ['salary_model_version_id', 'is_default', 'class_type_id'],
                'salary_model_rules_lookup_index',
            );
            $table->index(['account_id', 'class_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_model_class_rules');
    }
};
