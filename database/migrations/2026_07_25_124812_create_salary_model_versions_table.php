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
        Schema::create('salary_model_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('effective_from');
            $table->char('currency', 3);
            $table->string('period_unit', 20)->nullable();
            $table->unsignedInteger('amount_cents')->nullable();
            $table->json('counted_booking_statuses')->nullable();
            $table->boolean('pay_empty_classes')->default(false);
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(
                ['salary_model_id', 'effective_from', 'superseded_at'],
                'salary_model_versions_effective_index',
            );
            $table->index(['account_id', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_model_versions');
    }
};
