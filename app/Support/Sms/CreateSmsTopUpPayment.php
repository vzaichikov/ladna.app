<?php

namespace App\Support\Sms;

use App\Enums\IntegrationProvider;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Models\Account;
use App\Models\SmsTopUpPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class CreateSmsTopUpPayment
{
    public function __construct(private readonly SmsWalletService $wallets) {}

    public function execute(
        Account $account,
        int $amountCents,
        SmsTopUpKind $kind,
        ?string $idempotencyKey = null,
    ): SmsTopUpPayment {
        if ($account->isReadOnlyDemo()) {
            throw new LogicException('Read-only demo accounts cannot top up SMS credit.');
        }

        if ($amountCents <= 0) {
            throw new LogicException('SMS top-up amount must be positive.');
        }

        $idempotencyKey ??= 'sms-top-up:'.Str::uuid();

        return DB::transaction(function () use ($account, $amountCents, $kind, $idempotencyKey): SmsTopUpPayment {
            $existing = SmsTopUpPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $wallet = $this->wallets->walletFor($account);
            $paymentMethod = $account->subscription?->paymentMethod()->first();

            return SmsTopUpPayment::create([
                'account_id' => $account->id,
                'account_sms_wallet_id' => $wallet->id,
                'account_subscription_payment_method_id' => $paymentMethod?->id,
                'provider' => IntegrationProvider::Monopay->value,
                'kind' => $kind,
                'order_id' => 'SMS-'.Str::upper(Str::random(24)),
                'status' => SmsTopUpPaymentStatus::PaymentStarted,
                'amount_cents' => $amountCents,
                'currency' => 'UAH',
                'idempotency_key' => $idempotencyKey,
                'started_at' => now(),
            ]);
        }, attempts: 3);
    }
}
