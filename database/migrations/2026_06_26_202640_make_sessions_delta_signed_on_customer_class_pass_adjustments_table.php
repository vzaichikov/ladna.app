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
        Schema::table('customer_class_pass_adjustments', function (Blueprint $table) {
            $table->smallInteger('sessions_delta')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_class_pass_adjustments', function (Blueprint $table) {
            $table->unsignedSmallInteger('sessions_delta')->change();
        });
    }
};
