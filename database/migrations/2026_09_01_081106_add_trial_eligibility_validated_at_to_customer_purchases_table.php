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
        Schema::table('customer_purchases', function (Blueprint $table): void {
            $table->timestamp('trial_eligibility_validated_at')->nullable()->after('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_purchases', function (Blueprint $table): void {
            $table->dropColumn('trial_eligibility_validated_at');
        });
    }
};
