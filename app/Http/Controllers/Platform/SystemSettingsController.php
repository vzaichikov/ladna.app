<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingsRequest;
use App\Models\SystemSetting;
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
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request): RedirectResponse
    {
        SystemSetting::setValue(SystemAppearance::FontSettingKey, $request->validated('font_family'));

        return redirect()
            ->route('platform.settings.edit')
            ->with('status', __('app.system_settings_updated'));
    }
}
