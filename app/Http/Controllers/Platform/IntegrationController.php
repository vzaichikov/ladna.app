<?php

namespace App\Http\Controllers\Platform;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCentralSmsProviderRequest;
use App\Http\Requests\UpdatePlatformIntegrationRequest;
use App\Models\IntegrationSetting;
use App\Models\SystemSetting;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\IntegrationCatalog;
use App\Support\Payments\MonopayCheckoutSettings;
use App\Support\Sms\SmsServiceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(
        Request $request,
        CustomerAuthAvailability $availability,
        MonopayCheckoutSettings $monopayCheckoutSettings,
    ): View {
        $categories = IntegrationCatalog::categories(IntegrationScope::Platform);
        $activeCategory = IntegrationCatalog::activeCategory($request->query('tab'), IntegrationScope::Platform);
        $settings = IntegrationSetting::platform()
            ->orderBy('provider')
            ->get()
            ->keyBy(fn (IntegrationSetting $setting): string => $setting->provider->value);

        return view('integrations.index', [
            'title' => __('app.integrations'),
            'heading' => __('app.product_owner_integrations'),
            'copy' => __('app.product_owner_integrations_copy'),
            'categories' => $categories,
            'activeCategory' => $activeCategory,
            'providers' => IntegrationCatalog::providersForCategory($activeCategory, IntegrationScope::Platform),
            'settings' => $settings,
            'tabRoute' => 'platform.integrations.index',
            'tabRouteParameters' => [],
            'updateRoute' => 'platform.integrations.update',
            'updateRouteParameters' => [],
            'centralSmsProvider' => SystemSetting::stringValue(SystemSetting::CentralSmsProviderKey),
            'effectiveCentralSmsSetting' => $availability->platformSmsSetting(),
            'centralSmsProviderUpdateRoute' => $activeCategory === IntegrationCategory::Messaging
                ? 'platform.integrations.central-sms-provider.update'
                : null,
            'monopayEventIframeV2Enabled' => $monopayCheckoutSettings->eventIframeV2Enabled(),
        ]);
    }

    public function updateCentralSmsProvider(
        UpdateCentralSmsProviderRequest $request,
        CustomerAuthAvailability $availability,
        SmsServiceSettings $smsServiceSettings,
    ): RedirectResponse {
        $provider = $request->provider();

        if (! $availability->platformSmsSetting($provider)) {
            return back()
                ->withInput()
                ->withErrors(['central_sms_provider' => __('app.central_sms_provider_unavailable')]);
        }

        if (SystemSetting::stringValue(SystemSetting::CentralSmsProviderKey) !== $provider) {
            $smsServiceSettings->clearProviderBalanceStatus();
        }

        SystemSetting::setValue(SystemSetting::CentralSmsProviderKey, $provider);

        return redirect()
            ->route('platform.integrations.index', ['tab' => 'messaging'])
            ->with('status', __('app.central_sms_provider_updated'));
    }

    public function update(
        UpdatePlatformIntegrationRequest $request,
        string $provider,
        SmsServiceSettings $smsServiceSettings,
        MonopayCheckoutSettings $monopayCheckoutSettings,
    ): RedirectResponse {
        $category = IntegrationCatalog::providerCategory($provider);

        IntegrationSetting::updateOrCreate(
            [
                'scope_type' => IntegrationScope::Platform->value,
                'scope_id' => 0,
                'provider' => $provider,
            ],
            [
                'account_id' => null,
                'category' => $category->value,
                ...$request->payload(),
            ],
        );

        if ($provider === IntegrationProvider::Monopay->value) {
            $monopayCheckoutSettings->saveEventIframeV2Enabled($request->eventIframeV2Enabled());
        }

        if (
            $category === IntegrationCategory::Messaging
            && SystemSetting::stringValue(SystemSetting::CentralSmsProviderKey) === $provider
        ) {
            $smsServiceSettings->clearProviderBalanceStatus();
        }

        return redirect()
            ->route('platform.integrations.index', ['tab' => $category->value])
            ->with('status', __('app.integration_updated'));
    }
}
