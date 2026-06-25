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
        Schema::create('customer_class_pass_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_class_pass_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sessions_delta');
            $table->unsignedSmallInteger('previous_sessions_count');
            $table->unsignedSmallInteger('new_sessions_count');
            $table->text('reason');
            $table->timestamps();

            $table->index(['account_id', 'customer_class_pass_id'], 'class_pass_adjustments_account_pass_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_class_pass_adjustments');
    }
};
