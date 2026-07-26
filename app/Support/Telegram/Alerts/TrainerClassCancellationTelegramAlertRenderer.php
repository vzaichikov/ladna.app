<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\TelegramAlertType;
use App\Models\Account;
use Illuminate\Support\Facades\Lang;

class TrainerClassCancellationTelegramAlertRenderer implements TelegramAlertRenderer
{
    public function type(): TelegramAlertType
    {
        return TelegramAlertType::TrainerClassCancellation;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(Account $account, array $payload): string
    {
        $locale = $this->locale($account);
        $translationKey = ($payload['reason'] ?? null) === QueueTrainerClassCancellationTelegramAlert::ReasonAllBookingsCancelled
            ? 'app.telegram_alert_trainer_class_cancellation_customers_text'
            : 'app.telegram_alert_trainer_class_cancellation_studio_text';

        return Lang::get($translationKey, [
            'studio' => (string) ($payload['studio_name'] ?? $account->name),
            'trainer' => (string) ($payload['trainer_name'] ?? Lang::get('app.trainer', [], $locale)),
            'location' => (string) ($payload['location_name'] ?? Lang::get('app.not_set', [], $locale)),
            'room' => (string) ($payload['room_name'] ?? Lang::get('app.not_set', [], $locale)),
            'class' => (string) ($payload['class_name'] ?? Lang::get('app.class', [], $locale)),
            'time' => (string) ($payload['class_time'] ?? Lang::get('app.not_set', [], $locale)),
        ], $locale);
    }

    private function locale(Account $account): string
    {
        $locale = (string) $account->default_language;

        return array_key_exists($locale, config('ladna.locales', [])) ? $locale : config('app.locale');
    }
}
