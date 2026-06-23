<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationScope;
use App\Http\Requests\UpdateAccountIntegrationRequest;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Support\IntegrationCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountIntegrationController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        abort_unless($account->isOwnedBy($request->user()), 403);

        $activeCategory = IntegrationCatalog::activeCategory($request->query('tab'));
        $settings = IntegrationSetting::forAccount($account)
            ->orderBy('provider')
            ->get()
            ->keyBy(fn (IntegrationSetting $setting): string => $setting->provider->value);

        return view('integrations.index', [
            'account' => $account,
            'title' => __('app.integrations'),
            'heading' => __('app.studio_owner_integrations'),
            'copy' => __('app.studio_owner_integrations_copy'),
            'categories' => IntegrationCatalog::categories(),
            'activeCategory' => $activeCategory,
            'providers' => IntegrationCatalog::providersForCategory($activeCategory, IntegrationScope::Account),
            'settings' => $settings,
            'tabRoute' => 'dashboard.accounts.integrations.index',
            'tabRouteParameters' => [$account],
            'updateRoute' => 'dashboard.accounts.integrations.update',
            'updateRouteParameters' => [$account],
        ]);
    }

    public function update(UpdateAccountIntegrationRequest $request, Account $account, string $provider): RedirectResponse
    {
        $category = IntegrationCatalog::providerCategory($provider);

        IntegrationSetting::updateOrCreate(
            [
                'scope_type' => IntegrationScope::Account->value,
                'scope_id' => $account->id,
                'provider' => $provider,
            ],
            [
                'account_id' => $account->id,
                'category' => $category->value,
                ...$request->payload(),
            ],
        );

        return redirect()
            ->route('dashboard.accounts.integrations.index', [$account, 'tab' => $category->value])
            ->with('status', __('app.integration_updated'));
    }
}
