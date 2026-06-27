<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\SystemSetting;
use App\Support\AccountActivityLogSettings;
use App\Support\SystemAppearance;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SystemSettingsController extends Controller
{
    public function edit(): View
    {
        $fontOptions = SystemAppearance::fontOptions();

        return view('platform.settings.edit', [
            'fontOptions' => $fontOptions,
            'currentFontKey' => SystemAppearance::currentFontKey(),
            'previewFontsUrl' => SystemAppearance::googleFontsUrl($fontOptions),
            'supportUrl' => SystemSetting::stringValue(SystemSetting::SupportUrlKey),
            'activityLogEnabled' => AccountActivityLogSettings::enabled(),
            'activityLogRetentionDays' => AccountActivityLogSettings::retentionDays(),
            'activityLogMinRetentionDays' => AccountActivityLogSettings::MinRetentionDays,
            'activityLogMaxRetentionDays' => AccountActivityLogSettings::MaxRetentionDays,
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request): RedirectResponse
    {
        SystemSetting::setValue(SystemAppearance::FontSettingKey, $request->validated('font_family'));
        SystemSetting::setValue(SystemSetting::SupportUrlKey, $request->validated('support_url'));
        AccountActivityLogSettings::setEnabled(
            $request->has('activity_log_enabled')
                ? $request->boolean('activity_log_enabled')
                : AccountActivityLogSettings::enabled()
        );
        AccountActivityLogSettings::setRetentionDays(
            (int) ($request->validated('activity_log_retention_days') ?? AccountActivityLogSettings::retentionDays())
        );

        return redirect()
            ->route('platform.settings.edit')
            ->with('status', __('app.system_settings_updated'));
    }
}
