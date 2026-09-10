<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('account_sms_wallets')
            ->where('auto_top_up_enabled', true)
            ->whereNotNull('auto_top_up_suspended_at')
            ->whereColumn('last_auto_top_up_failure_warning_at', 'auto_top_up_suspended_at')
            ->whereExists(fn (Builder $query): Builder => $query
                ->selectRaw('1')
                ->from('accounts')
                ->whereColumn('accounts.id', 'account_sms_wallets.account_id')
                ->where('status', 'active')
                ->where('mode', '!=', 'demo_readonly'))
            ->whereExists(fn (Builder $query): Builder => $query
                ->selectRaw('1')
                ->from('email_deliveries')
                ->whereColumn('email_deliveries.account_id', 'account_sms_wallets.account_id')
                ->where('scenario', 'sms_auto_top_up_failed')
                ->whereColumn('email_deliveries.created_at', 'account_sms_wallets.auto_top_up_suspended_at')
                ->where('payload->reason', 'monthly_cap_exceeded'))
            ->whereNotExists(fn (Builder $query): Builder => $query
                ->selectRaw('1')
                ->from('email_deliveries')
                ->whereColumn('email_deliveries.account_id', 'account_sms_wallets.account_id')
                ->where('scenario', 'sms_auto_top_up_failed')
                ->whereColumn('email_deliveries.created_at', '>=', 'account_sms_wallets.auto_top_up_suspended_at')
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('payload->reason')
                    ->orWhere('payload->reason', '!=', 'monthly_cap_exceeded')))
            ->whereExists(fn (Builder $query): Builder => $query
                ->selectRaw('1')
                ->from('account_subscription_payment_methods as methods')
                ->join('account_subscriptions as subscriptions', 'subscriptions.id', '=', 'methods.account_subscription_id')
                ->whereColumn('subscriptions.account_id', 'account_sms_wallets.account_id')
                ->whereColumn('methods.account_id', 'account_sms_wallets.account_id')
                ->where('methods.status', 'active')
                ->whereNotNull('methods.provider_card_token')
                ->whereNull('methods.revoked_at')
                ->whereColumn('methods.verified_at', '<', 'account_sms_wallets.auto_top_up_suspended_at')
                ->whereColumn('methods.updated_at', '<', 'account_sms_wallets.auto_top_up_suspended_at'))
            ->whereNotExists(fn (Builder $query): Builder => $query
                ->selectRaw('1')
                ->from('sms_top_up_payments')
                ->whereColumn('sms_top_up_payments.account_id', 'account_sms_wallets.account_id')
                ->where('kind', 'automatic')
                ->where(fn (Builder $query): Builder => $query
                    ->whereIn('status', ['payment_started', 'payment_pending'])
                    ->orWhere(fn (Builder $query): Builder => $query
                        ->whereIn('status', ['payment_failed', 'payment_cancelled', 'payment_expired'])
                        ->where(fn (Builder $query): Builder => $query
                            ->whereColumn('sms_top_up_payments.updated_at', '>=', 'account_sms_wallets.auto_top_up_suspended_at')
                            ->orWhereColumn('failed_at', '>=', 'account_sms_wallets.auto_top_up_suspended_at')
                            ->orWhereColumn('cancelled_at', '>=', 'account_sms_wallets.auto_top_up_suspended_at')
                            ->orWhereColumn('expired_at', '>=', 'account_sms_wallets.auto_top_up_suspended_at')))))
            ->update(['auto_top_up_suspended_at' => null]);
    }

    /**
     * This data repair is forward-only; recovered wallets must not be suspended again.
     */
    public function down(): void {}
};
