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
        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->string('schedule_kind')->nullable()->change();
            $table->unsignedSmallInteger('sessions_count')->nullable()->change();
            $table->unsignedSmallInteger('validity_days')->nullable()->change();
            $table->unsignedSmallInteger('total_validity_days')->nullable()->default(180)->change();
            $table->unsignedInteger('promo_buy_quantity')->nullable();
            $table->unsignedInteger('promo_free_quantity')->nullable();
            $table->char('checkout_fingerprint', 64)->nullable();
            $table->char('access_token_hash', 64)->nullable()->unique();
            $table->text('access_token_encrypted')->nullable();
            $table->timestamp('gateway_start_requested_at')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_user_id');
            $table->dropConstrainedForeignId('actor_trainer_id');
            $table->dropUnique(['access_token_hash']);
            $table->dropColumn(['promo_buy_quantity', 'promo_free_quantity', 'checkout_fingerprint', 'access_token_hash', 'access_token_encrypted', 'gateway_start_requested_at', 'actor_name', 'actor_email', 'actor_role']);
        });
    }
};
