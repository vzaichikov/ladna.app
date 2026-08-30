<?php

namespace App\Support\Festivals;

use App\Enums\FestivalNotificationType;
use App\Models\FestivalNotificationSetting;

final class FestivalNotificationScenarioSettings
{
    public function emailIsEnabled(int $accountId, FestivalNotificationType $type): bool
    {
        return $this->channelIsEnabled($accountId, $type, 'send_email', true);
    }

    public function smsIsEnabled(int $accountId, FestivalNotificationType $type): bool
    {
        return $this->channelIsEnabled($accountId, $type, 'send_sms', false);
    }

    public function telegramIsEnabled(int $accountId, FestivalNotificationType $type): bool
    {
        return $this->channelIsEnabled($accountId, $type, 'send_telegram', true);
    }

    public function ownerTelegramIsEnabled(int $accountId, FestivalNotificationType $type): bool
    {
        return $this->channelIsEnabled($accountId, $type, 'notify_owner_telegram', false);
    }

    private function channelIsEnabled(int $accountId, FestivalNotificationType $type, string $column, bool $default): bool
    {
        $enabled = FestivalNotificationSetting::query()
            ->where('account_id', $accountId)
            ->where('type', $type->value)
            ->value($column);

        $fallbackType = $type->settingsFallback();
        if ($enabled === null && $fallbackType) {
            $enabled = FestivalNotificationSetting::query()
                ->where('account_id', $accountId)
                ->where('type', $fallbackType->value)
                ->value($column);
        }

        return $enabled === null ? $default : (bool) $enabled;
    }
}
