<?php

namespace App\Http\Controllers\Platform;

use App\Enums\IntegrationProvider;
use App\Enums\SmsSendingMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFestivalLandingTemplatesRequest;
use App\Http\Requests\UpdateStudioPossibilitiesRequest;
use App\Models\Account;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\Festivals\FestivalLandingRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StudioPossibilitiesController extends Controller
{
    public function edit(
        Request $request,
        Account $account,
        CustomerAuthAvailability $availability,
        FestivalLandingRegistry $landingRegistry,
    ): View {
        $activeTab = in_array($request->query('tab'), ['capabilities', 'festival-templates'], true)
            ? (string) $request->query('tab')
            : 'capabilities';

        return view('platform.accounts.studio-possibilities', [
            'account' => $account,
            'activeTab' => $activeTab,
            'settings' => $availability->settingsFor($account),
            'readiness' => $availability->readinessFor($account),
            'smsSendingModes' => SmsSendingMode::cases(),
            'smsProviders' => [
                IntegrationProvider::Turbosms,
                IntegrationProvider::Smsclub,
                IntegrationProvider::Sendpulse,
            ],
            'festivalLandingTemplates' => $landingRegistry->templates(),
            'allowedFestivalLandingTemplateKeys' => $landingRegistry->availableTemplateKeys($account),
        ]);
    }

    public function update(UpdateStudioPossibilitiesRequest $request, Account $account): RedirectResponse
    {
        $account->update($request->accountFeaturePayload());

        $account->customerAuthSetting()->updateOrCreate(
            ['account_id' => $account->id],
            $request->customerAuthenticationPayload(),
        );

        return redirect()
            ->route('platform.accounts.studio-possibilities.edit', $account)
            ->with('status', __('app.studio_possibilities_updated'));
    }

    public function updateFestivalTemplates(
        UpdateFestivalLandingTemplatesRequest $request,
        Account $account,
    ): RedirectResponse {
        $account->update([
            'allowed_festival_landing_templates' => $request->templateKeys(),
        ]);

        return redirect()
            ->route('platform.accounts.studio-possibilities.edit', [$account, 'tab' => 'festival-templates'])
            ->with('status', __('app.festival_landing_template_grants_updated'));
    }
}
