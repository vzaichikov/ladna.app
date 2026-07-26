<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('customer_auth_settings')
            ->where('otp_sender_scope', 'platform')
            ->whereNull('otp_provider')
            ->update(['otp_sender_scope' => 'account']);

        DB::table('customer_auth_settings')
            ->where('customer_sms_sender_scope', 'platform')
            ->whereNull('customer_sms_provider')
            ->update(['customer_sms_sender_scope' => 'account']);
    }

    /**
     * The normalization is intentionally irreversible because account/null rows
     * may have been created explicitly after this migration ran.
     */
    public function down(): void {}
};
