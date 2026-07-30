<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterPlatformAiUsageRequest;
use App\Http\Requests\UpdatePlatformAiFirewallRequest;
use App\Models\Account;
use App\Models\AiProviderRequest;
use App\Models\PlatformAiSetting;
use App\Models\User;
use App\Support\Ai\PlatformAiUsageReport;
use App\Support\Ai\StudioAiUsageFirewall;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AiUsageController extends Controller
{
    public function index(
        FilterPlatformAiUsageRequest $request,
        PlatformAiUsageReport $report,
    ): View {
        $filters = $request->validated();

        return view('platform.ai-usage.index', [
            'platformAiSetting' => PlatformAiSetting::current(),
            'usage' => $report->build($filters),
            'filters' => $filters,
            'accountOptions' => Account::query()
                ->select(['id', 'name'])
                ->whereIn('id', AiProviderRequest::query()->select('account_id')->whereNotNull('account_id'))
                ->orderBy('name')
                ->limit(250)
                ->get(),
            'userOptions' => User::query()
                ->select(['id', 'name'])
                ->whereIn('id', AiProviderRequest::query()->select('user_id')->whereNotNull('user_id'))
                ->orderBy('name')
                ->limit(250)
                ->get(),
            'channelOptions' => AiProviderRequest::query()
                ->whereNotNull('channel')
                ->distinct()
                ->orderBy('channel')
                ->pluck('channel'),
            'providerOptions' => AiProviderRequest::query()
                ->whereNotNull('provider')
                ->distinct()
                ->orderBy('provider')
                ->pluck('provider'),
            'modelOptions' => AiProviderRequest::query()
                ->whereNotNull('model')
                ->distinct()
                ->orderBy('model')
                ->pluck('model'),
        ]);
    }

    public function update(UpdatePlatformAiFirewallRequest $request): RedirectResponse
    {
        PlatformAiSetting::current()->fill($request->validated())->save();

        return redirect()
            ->route('platform.ai-usage.index')
            ->with('status', __('app.ai_firewall_settings_updated'));
    }

    public function resetUser(
        User $user,
        StudioAiUsageFirewall $firewall,
    ): RedirectResponse {
        $firewall->resetUser($user, request()->user());

        return back()->with('status', __('app.ai_firewall_user_reset', ['name' => $user->name]));
    }

    public function resetAccount(
        Account $account,
        StudioAiUsageFirewall $firewall,
    ): RedirectResponse {
        $firewall->resetAccount($account);

        return back()->with('status', __('app.ai_firewall_account_reset', ['name' => $account->name]));
    }
}
