<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Database\QueryException;

class FoundersProgramSettings
{
    public const PageEnabledSettingKey = 'founders.page_enabled';

    public const BannerEnabledSettingKey = 'founders.banner_enabled';

    public const RemainingStudiosSettingKey = 'founders.remaining_studios';

    public const MinRemainingStudios = 1;

    public const MaxRemainingStudios = 999;

    /**
     * @return array{
     *     page_enabled: bool,
     *     banner_enabled: bool,
     *     remaining_studios: int,
     *     support_url: string|null,
     *     page_available: bool,
     *     banner_visible: bool
     * }
     */
    public function current(): array
    {
        try {
            $values = SystemSetting::query()
                ->whereIn('key', [
                    self::PageEnabledSettingKey,
                    self::BannerEnabledSettingKey,
                    self::RemainingStudiosSettingKey,
                    SystemSetting::SupportUrlKey,
                ])
                ->pluck('value', 'key');
        } catch (QueryException) {
            $values = collect();
        }

        $pageEnabled = $this->booleanValue($values->get(self::PageEnabledSettingKey));
        $bannerEnabled = $this->booleanValue($values->get(self::BannerEnabledSettingKey));
        $remainingStudios = $this->normalizeRemainingStudios(
            (int) $values->get(self::RemainingStudiosSettingKey, 0)
        );
        $supportUrlValue = $values->get(SystemSetting::SupportUrlKey);
        $supportUrl = is_string($supportUrlValue) && filled($supportUrlValue)
            ? trim($supportUrlValue)
            : null;
        $pageAvailable = $pageEnabled
            && $remainingStudios >= self::MinRemainingStudios
            && $supportUrl !== null;

        return [
            'page_enabled' => $pageEnabled,
            'banner_enabled' => $bannerEnabled,
            'remaining_studios' => $remainingStudios,
            'support_url' => $supportUrl,
            'page_available' => $pageAvailable,
            'banner_visible' => $pageAvailable && $bannerEnabled,
        ];
    }

    public function save(bool $pageEnabled, bool $bannerEnabled, int $remainingStudios): void
    {
        SystemSetting::setValue(self::PageEnabledSettingKey, $pageEnabled ? '1' : '0');
        SystemSetting::setValue(self::BannerEnabledSettingKey, $bannerEnabled ? '1' : '0');
        SystemSetting::setValue(
            self::RemainingStudiosSettingKey,
            (string) $this->normalizeRemainingStudios($remainingStudios)
        );
    }

    private function booleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function normalizeRemainingStudios(int $remainingStudios): int
    {
        return min(max($remainingStudios, 0), self::MaxRemainingStudios);
    }
}
