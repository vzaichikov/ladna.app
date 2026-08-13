<?php

namespace App\Support\Festivals;

use App\Models\SystemSetting;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

class FestivalPoweredBannerSettings
{
    public const EnabledSettingKey = 'festivals.powered_banner_enabled';

    public const DismissedCookieName = 'ladna_festival_powered_banner_dismissed';

    public function enabled(): bool
    {
        try {
            $value = SystemSetting::stringValue(self::EnabledSettingKey, '0');
        } catch (QueryException) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public function visible(Request $request): bool
    {
        return $this->enabled() && $request->cookie(self::DismissedCookieName) !== '1';
    }

    public function save(bool $enabled): void
    {
        SystemSetting::setValue(self::EnabledSettingKey, $enabled ? '1' : '0');
    }

    public function dismissalCookie(Request $request): HttpCookie
    {
        return Cookie::forever(
            self::DismissedCookieName,
            '1',
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        );
    }
}
