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
        Schema::create('salary_model_class_rule_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_model_class_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('minimum_people');
            $table->unsignedSmallInteger('maximum_people')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->timestamps();

            $table->index(
                ['salary_model_class_rule_id', 'minimum_people'],
                'salary_model_rule_tiers_lookup_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_model_class_rule_tiers');
    }
};
