<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use App\Enums\SmsSendingMode;
use App\Http\Requests\UpdateAccountIntegrationRequest;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\IntegrationCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountIntegrationController extends Controller
{
    public function show(
        Request $request,
        Account $account,
        IntegrationCategory $category,
        CustomerAuthAvailability $customerAuthAvailability,
    ): View {
        abort_unless($account->isOwnedBy($request->user()), 403);

        $categories = IntegrationCatalog::categories(IntegrationScope::Account);
        abort_unless(array_key_exists($category->value, $categories), 404);

        $settings = IntegrationSetting::forAccount($account)
            ->orderBy('provider')
            ->get()
            ->keyBy(fn (IntegrationSetting $setting): string => $setting->provider->value);
        $smsSettings = $category === IntegrationCategory::Messaging
            ? $customerAuthAvailability->settingsFor($account)
            : null;

        return view('integrations.index', [
            'account' => $account,
            'title' => __($category->labelKey()),
            'heading' => __($category->labelKey()),
            'copy' => __('app.studio_owner_integrations_copy'),
            'categories' => $categories,
            'activeCategory' => $category,
            'providers' => IntegrationCatalog::providersForCategory($category, IntegrationScope::Account),
            'settings' => $settings,
            'updateRoute' => 'dashboard.accounts.integrations.update',
            'updateRouteParameters' => ['account' => $account],
            'smsSendingModes' => SmsSendingMode::cases(),
            'smsSettings' => $smsSettings,
            'smsReadiness' => $smsSettings
                ? $customerAuthAvailability->readinessFor($account)
                : null,
            'smsSendingModeUpdateRoute' => 'dashboard.accounts.integrations.sms-sending.update',
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
            ->route('dashboard.accounts.integrations.show', [$account, $category])
            ->with('status', __('app.integration_updated'));
    }
}
