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
        Schema::table('class_pass_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('total_validity_days')->default(180)->after('validity_days');
        });

        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->unsignedSmallInteger('total_validity_days')->default(180)->after('validity_days');
        });

        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->unsignedSmallInteger('total_validity_days')->default(180)->after('validity_days');
            $table->timestamp('usable_until_at')->nullable()->after('expires_at');
            $table->index(['account_id', 'is_active', 'status', 'usable_until_at'], 'customer_passes_account_active_usable_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->dropIndex('customer_passes_account_active_usable_index');
            $table->dropColumn(['total_validity_days', 'usable_until_at']);
        });

        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->dropColumn('total_validity_days');
        });

        Schema::table('class_pass_plans', function (Blueprint $table) {
            $table->dropColumn('total_validity_days');
        });
    }
};
