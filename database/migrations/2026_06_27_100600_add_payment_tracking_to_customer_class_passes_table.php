<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->foreignId('issued_location_id')->nullable()->after('source')->constrained('locations')->nullOnDelete();
            $table->boolean('is_paid')->default(false)->after('issued_location_id');

            $table->index(['account_id', 'is_active', 'is_paid'], 'customer_passes_account_active_paid_idx');
        });

        DB::table('customer_class_passes')
            ->where('source', 'online_payment')
            ->update(['is_paid' => true]);

        DB::table('customer_class_passes')
            ->whereIn('id', function ($query): void {
                $query->select('customer_class_pass_id')
                    ->from('customer_purchases')
                    ->where('status', 'payment_paid')
                    ->whereNotNull('customer_class_pass_id');
            })
            ->update(['is_paid' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->dropIndex('customer_passes_account_active_paid_idx');
            $table->dropForeign(['issued_location_id']);
            $table->dropColumn(['issued_location_id', 'is_paid']);
        });
    }
};
