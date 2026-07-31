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
        Schema::table('account_subscription_payment_methods', function (Blueprint $table): void {
            $table->string('verification_purpose')
                ->default('subscription')
                ->after('verification_invoice_id');
            $table->unsignedBigInteger('verification_amount_cents')
                ->nullable()
                ->after('verification_purpose');

            $table->index(
                'verification_purpose',
                'saas_payment_methods_verify_purpose_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_subscription_payment_methods', function (Blueprint $table): void {
            $table->dropIndex('saas_payment_methods_verify_purpose_idx');
            $table->dropColumn([
                'verification_purpose',
                'verification_amount_cents',
            ]);
        });
    }
};
