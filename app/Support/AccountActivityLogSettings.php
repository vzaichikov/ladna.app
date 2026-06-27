<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Database\QueryException;

class AccountActivityLogSettings
{
    public const EnabledSettingKey = 'account_activity_log.enabled';

    public const RetentionDaysSettingKey = 'account_activity_log.retention_days';

    public const DefaultEnabled = true;

    public const DefaultRetentionDays = 90;

    public const MinRetentionDays = 1;

    public const MaxRetentionDays = 3650;

    public static function enabled(): bool
    {
        try {
            $storedValue = SystemSetting::stringValue(self::EnabledSettingKey);
        } catch (QueryException) {
            return self::DefaultEnabled;
        }

        if ($storedValue === null) {
            return self::DefaultEnabled;
        }

        return filter_var($storedValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? self::DefaultEnabled;
    }

    public static function retentionDays(): int
    {
        try {
            $storedValue = SystemSetting::stringValue(self::RetentionDaysSettingKey);
        } catch (QueryException) {
            return self::DefaultRetentionDays;
        }

        $days = filter_var($storedValue, FILTER_VALIDATE_INT);

        if (! is_int($days)) {
            return self::DefaultRetentionDays;
        }

        return self::normalizeRetentionDays($days);
    }

    public static function setEnabled(bool $enabled): void
    {
        SystemSetting::setValue(self::EnabledSettingKey, $enabled ? '1' : '0');
    }

    public static function setRetentionDays(int $days): void
    {
        SystemSetting::setValue(self::RetentionDaysSettingKey, (string) self::normalizeRetentionDays($days));
    }

    private static function normalizeRetentionDays(int $days): int
    {
        return min(max($days, self::MinRetentionDays), self::MaxRetentionDays);
    }
}
