<?php

namespace App\Http\Controllers\Platform;

use App\Enums\IntegrationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePlatformIntegrationRequest;
use App\Models\IntegrationSetting;
use App\Support\IntegrationCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(Request $request): View
    {
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
        ]);
    }

    public function update(UpdatePlatformIntegrationRequest $request, string $provider): RedirectResponse
    {
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

        return redirect()
            ->route('platform.integrations.index', ['tab' => $category->value])
            ->with('status', __('app.integration_updated'));
    }
}
