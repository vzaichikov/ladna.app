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
        $this->useRestrictOnDelete('sms_wallet_ledger_entries');
        $this->useRestrictOnDelete('subscription_plan_sms_rate_changes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->useNullOnDelete('sms_wallet_ledger_entries');
        $this->useNullOnDelete('subscription_plan_sms_rate_changes');
    }

    private function useRestrictOnDelete(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    private function useNullOnDelete(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropForeign(['actor_user_id']);
            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
