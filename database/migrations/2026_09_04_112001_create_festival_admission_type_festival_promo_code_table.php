<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('festival_admission_type_festival_promo_code', function (Blueprint $table) {
            $table->foreignId('festival_admission_type_id');
            $table->foreignId('festival_promo_code_id');
            $table->primary(['festival_admission_type_id', 'festival_promo_code_id'], 'festival_admission_promo_primary');
            $table->foreign('festival_admission_type_id', 'festival_admission_promo_type_fk')
                ->references('id')->on('festival_admission_types')->cascadeOnDelete();
            $table->foreign('festival_promo_code_id', 'festival_admission_promo_code_fk')
                ->references('id')->on('festival_promo_codes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('festival_admission_type_festival_promo_code');
    }
};
