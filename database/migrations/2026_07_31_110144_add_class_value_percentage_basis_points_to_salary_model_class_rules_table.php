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
        Schema::table('salary_model_class_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('class_value_percentage_basis_points')
                ->nullable()
                ->after('formula_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salary_model_class_rules', function (Blueprint $table) {
            $table->dropColumn('class_value_percentage_basis_points');
        });
    }
};
