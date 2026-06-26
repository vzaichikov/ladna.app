<?php

namespace App\Support\Mail;

use App\Enums\IntegrationProvider;
use App\Enums\MailEngine;
use App\Models\IntegrationSetting;
use App\Support\IntegrationCatalog;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;

class MailDeliverySettingsResolver
{
    private const MailerName = 'ladna_transactional';

    private const PrimaryMailerName = 'ladna_transactional_primary';

    private const FallbackMailerName = 'ladna_transactional_fallback';

    public function resolve(): MailDeliverySettings
    {
        $setting = IntegrationSetting::platform()
            ->where('provider', IntegrationProvider::MailDelivery->value)
            ->first();

        $credentials = $setting?->is_enabled ? $setting->readableCredentials() : [];

        if (! $setting?->is_enabled || ! IntegrationCatalog::hasRequiredCredentials(IntegrationProvider::MailDelivery->value, $credentials)) {
            return $this->fallbackSettings();
        }

        $engine = MailEngine::tryFrom((string) ($credentials['engine'] ?? null)) ?? MailEngine::Log;
        $fromEmail = trim((string) ($credentials['mail_from_email'] ?? config('mail.from.address')));
        $fromName = trim((string) ($credentials['mail_from_name'] ?? config('mail.from.name')));

        if (! filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || $fromName === '') {
            return $this->fallbackSettings();
        }

        $this->configureMailer($engine, $credentials);

        return new MailDeliverySettings(
            mailer: self::MailerName,
            fromEmail: $fromEmail,
            fromName: $fromName,
            engine: $engine,
            configured: true,
        );
    }

    private function fallbackSettings(): MailDeliverySettings
    {
        $mailer = (string) config('mail.default', MailEngine::Log->value);

        return new MailDeliverySettings(
            mailer: $mailer,
            fromEmail: (string) config('mail.from.address', 'hello@example.com'),
            fromName: (string) config('mail.from.name', config('app.name', 'Ladna')),
            engine: MailEngine::tryFrom($mailer) ?? MailEngine::Log,
            configured: false,
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function configureMailer(MailEngine $engine, array $credentials): void
    {
        if ($engine === MailEngine::SendpulseSmtp || $engine === MailEngine::Smtp) {
            config([
                'mail.mailers.'.self::PrimaryMailerName => $this->smtpConfig($credentials),
                'mail.mailers.'.self::FallbackMailerName => $this->localConfig((string) ($credentials['fallback_engine'] ?? MailEngine::Log->value)),
                'mail.mailers.'.self::MailerName => [
                    'transport' => 'failover',
                    'mailers' => [
                        self::PrimaryMailerName,
                        self::FallbackMailerName,
                    ],
                    'retry_after' => 60,
                ],
            ]);
        } else {
            config([
                'mail.mailers.'.self::MailerName => $this->localConfig($engine->value),
            ]);
        }

        $mailManager = app('mail.manager');

        if (! $mailManager instanceof MailManager) {
            return;
        }

        foreach ([self::MailerName, self::PrimaryMailerName, self::FallbackMailerName] as $mailer) {
            $mailManager->purge($mailer);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function localConfig(string $engine): array
    {
        return match ($engine) {
            MailEngine::Sendmail->value => Arr::except(config('mail.mailers.sendmail', ['transport' => 'sendmail']), ['url']),
            default => Arr::except(config('mail.mailers.log', ['transport' => 'log']), ['url']),
        };
    }
}
