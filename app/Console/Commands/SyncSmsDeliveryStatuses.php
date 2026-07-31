<?php

namespace App\Console\Commands;

use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Models\IntegrationSetting;
use App\Models\SmsDelivery;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\CustomerAuth\SmsGatewayDeliveryStatus;
use App\Support\CustomerAuth\SmsGatewayResolver;
use App\Support\CustomerAuth\SmsGatewayStatusProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Signature('sms-deliveries:sync-statuses {--limit=1000}')]
#[Description('Synchronize pending SMS delivery statuses with capable providers')]
class SyncSmsDeliveryStatuses extends Command
{
    public function handle(
        CustomerAuthAvailability $availability,
        SmsGatewayResolver $gateways,
    ): int {
        $deliveries = SmsDelivery::query()
            ->dueForStatusCheck()
            ->whereNotNull('provider_message_id')
            ->with(['account.customerAuthSetting'])
            ->orderBy('next_status_check_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $grouped = [];

        foreach ($deliveries as $delivery) {
            $setting = match ($delivery->source_mode) {
                SmsSendingMode::LadnaService => $availability->platformSmsSetting($delivery->provider),
                SmsSendingMode::OwnGateway => $availability->accountSmsSetting(
                    $delivery->account,
                    $delivery->provider,
                ),
                SmsSendingMode::Disabled => null,
            };

            if (! $setting || $setting->provider->value !== $delivery->provider) {
                $this->expirePolling($delivery, 'sms_provider_configuration_changed');

                continue;
            }

            $grouped[$setting->id]['setting'] = $setting;
            $grouped[$setting->id]['deliveries'][] = $delivery;
        }

        $checked = 0;
        $updated = 0;

        foreach ($grouped as $group) {
            /** @var IntegrationSetting $setting */
            $setting = $group['setting'];
            /** @var Collection<int, SmsDelivery> $providerDeliveries */
            $providerDeliveries = collect($group['deliveries']);
            $gateway = $gateways->resolve($setting);

            if (! $gateway instanceof SmsGatewayStatusProvider) {
                $providerDeliveries->each(
                    fn (SmsDelivery $delivery) => $this->expirePolling($delivery, 'provider_status_unsupported'),
                );

                continue;
            }

            $batches = $providerDeliveries->chunk($gateway->maxStatusBatchSize())->values();

            foreach ($batches as $batchIndex => $batch) {
                $result = $gateway->fetchDeliveryStatuses(
                    $batch->pluck('provider_message_id')->filter()->values()->all(),
                );
                $checked += $batch->count();

                if (! $result->successful) {
                    $batch->each(fn (SmsDelivery $delivery) => $this->reschedule(
                        $delivery,
                        Str::limit((string) $result->message, 1000, ''),
                    ));

                    continue;
                }

                foreach ($batch as $delivery) {
                    $providerMessageId = (string) $delivery->provider_message_id;
                    $status = $result->statuses[$providerMessageId] ?? SmsGatewayDeliveryStatus::Unknown;
                    $updated += $this->applyStatus($delivery, $status) ? 1 : 0;
                }

                if ($batchIndex < $batches->count() - 1) {
                    usleep((int) ceil(1_000_000 / max(1, $gateway->maxStatusRequestsPerSecond())));
                }
            }
        }

        $this->info("Checked {$checked} SMS deliveries; updated {$updated}.");

        return self::SUCCESS;
    }

    private function applyStatus(SmsDelivery $delivery, SmsGatewayDeliveryStatus $status): bool
    {
        if ($status === SmsGatewayDeliveryStatus::Delivered) {
            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Delivered,
                'delivered_at' => now(),
                'last_status_checked_at' => now(),
                'next_status_check_at' => null,
                'last_error' => null,
            ])->save();

            return true;
        }

        if ($status === SmsGatewayDeliveryStatus::Undelivered) {
            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Undelivered,
                'failed_at' => now(),
                'last_status_checked_at' => now(),
                'next_status_check_at' => null,
                'error_code' => 'provider_undelivered',
            ])->save();

            return true;
        }

        $this->reschedule($delivery);

        return false;
    }

    private function reschedule(SmsDelivery $delivery, ?string $error = null): void
    {
        if ($delivery->status_polling_expires_at?->isPast()) {
            $this->expirePolling($delivery, 'status_polling_expired');

            return;
        }

        $acceptedAt = $delivery->accepted_at ?? $delivery->created_at;
        $nextCheckAt = $acceptedAt?->diffInHours(now()) < 2
            ? now()->addMinutes(5)
            : now()->addHour();

        $delivery->forceFill([
            'last_status_checked_at' => now(),
            'next_status_check_at' => $nextCheckAt,
            'last_error' => filled($error) ? $error : $delivery->last_error,
        ])->save();
    }

    private function expirePolling(SmsDelivery $delivery, string $errorCode): void
    {
        $delivery->forceFill([
            'last_status_checked_at' => now(),
            'next_status_check_at' => null,
            'error_code' => $errorCode,
        ])->save();
    }
}
