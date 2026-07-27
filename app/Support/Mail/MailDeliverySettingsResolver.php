<?php

namespace App\Support\Mail;

use App\Enums\IntegrationProvider;
use App\Enums\MailEngine;
use App\Models\IntegrationSetting;
use App\Support\IntegrationCatalog;

class MailDeliverySettingsResolver
{
    public const MailerName = 'ladna_transactional';

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
            mailer: self::MailerName,
            fromEmail: (string) config('mail.from.address', 'hello@example.com'),
            fromName: (string) config('mail.from.name', config('app.name', 'Ladna')),
            engine: MailEngine::tryFrom($mailer) ?? MailEngine::Log,
            configured: false,
        );
    }
}
