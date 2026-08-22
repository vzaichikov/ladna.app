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
        $hasAccountMismatch = DB::table('festival_payment_attempts')
            ->join('festival_charges', 'festival_charges.id', '=', 'festival_payment_attempts.festival_charge_id')
            ->whereColumn('festival_payment_attempts.account_id', '!=', 'festival_charges.account_id')
            ->exists();

        if ($hasAccountMismatch) {
            throw new LogicException('Festival payment attempt account does not match its charge.');
        }

        DB::table('festival_payment_attempts')
            ->orderBy('id')
            ->chunkById(500, function ($attempts): void {
                $timestamp = now();

                DB::table('festival_payment_attempt_charges')->insertOrIgnore(
                    $attempts->map(fn ($attempt): array => [
                        'account_id' => $attempt->account_id,
                        'festival_payment_attempt_id' => $attempt->id,
                        'festival_charge_id' => $attempt->festival_charge_id,
                        'amount_cents' => $attempt->amount_cents,
                        'currency' => $attempt->currency,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])->all(),
                );
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
