<?php

namespace App\Support\Mail;

use App\Enums\IntegrationProvider;
use App\Enums\MailEngine;
use App\Models\IntegrationSetting;
use App\Support\IntegrationCatalog;
use App\Support\SendPulse\SendPulseApiClient;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

class MailDeliveryTransportResolver
{
    public function __construct(
        private readonly MailManager $mailManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolve(): TransportInterface
    {
        $setting = IntegrationSetting::platform()
            ->where('provider', IntegrationProvider::MailDelivery->value)
            ->first();
        $credentials = $setting?->is_enabled ? $setting->readableCredentials() : [];

        if (
            ! $setting?->is_enabled
            || ! IntegrationCatalog::hasRequiredCredentials(IntegrationProvider::MailDelivery->value, $credentials)
        ) {
            return $this->localTransport(MailEngine::Log->value);
        }

        $engine = MailEngine::tryFrom((string) ($credentials['engine'] ?? null)) ?? MailEngine::Log;

        if ($engine === MailEngine::SendpulseApi) {
            return $this->withFallback(
                new SendPulseApiTransport(new SendPulseApiClient((string) $credentials['sendpulse_api_key'])),
                $credentials,
            );
        }

        if (in_array($engine, [MailEngine::SendpulseSmtp, MailEngine::Smtp], true)) {
            return $this->withFallback(
                $this->mailManager->createSymfonyTransport($this->smtpConfig($credentials)),
                $credentials,
            );
        }

        return $this->localTransport($engine->value);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function withFallback(TransportInterface $primary, array $credentials): TransportInterface
    {
        return new FailoverTransport(
            [
                $primary,
                $this->localTransport((string) ($credentials['fallback_engine'] ?? MailEngine::Log->value)),
            ],
            60,
            $this->logger,
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function smtpConfig(array $credentials): array
    {
        $encryption = (string) ($credentials['smtp_encryption'] ?? 'tls');

        return [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'url' => null,
            'host' => (string) $credentials['smtp_host'],
            'port' => (int) $credentials['smtp_port'],
            'username' => (string) $credentials['smtp_login'],
            'password' => (string) $credentials['smtp_password'],
            'timeout' => 10,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ];
    }

    private function localTransport(string $engine): TransportInterface
    {
        $config = match ($engine) {
            MailEngine::Sendmail->value => Arr::except(config('mail.mailers.sendmail', ['transport' => 'sendmail']), ['url']),
            default => Arr::except(config('mail.mailers.log', ['transport' => 'log']), ['url']),
        };

        return $this->mailManager->createSymfonyTransport($config);
    }
}
