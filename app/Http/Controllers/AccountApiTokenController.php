<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountApiTokenRequest;
use App\Models\Account;
use App\Models\AccountApiToken;
use App\Support\AccountApiTokenIssuer;
use Illuminate\Http\RedirectResponse;

class AccountApiTokenController extends Controller
{
    public function store(StoreAccountApiTokenRequest $request, Account $account, AccountApiTokenIssuer $accountApiTokenIssuer): RedirectResponse
    {
        $accountApiTokenIssuer->issue($account, (string) $request->validated('name'));

        return redirect()->route('dashboard.accounts.brand.edit', [$account, 'tab' => 'api'])
            ->with('status', __('app.api_token_created'));
    }

    public function regenerate(Account $account, AccountApiToken $accountApiToken, AccountApiTokenIssuer $accountApiTokenIssuer): RedirectResponse
    {
        $this->authorize('manageStudioSettings', $account);
        $this->ensureBelongsToAccount($account, $accountApiToken);
        $accountApiTokenIssuer->regenerate($accountApiToken);

        return redirect()->route('dashboard.accounts.brand.edit', [$account, 'tab' => 'api'])
            ->with('status', __('app.api_token_regenerated'));
    }

    public function destroy(Account $account, AccountApiToken $accountApiToken): RedirectResponse
    {
        $this->authorize('manageStudioSettings', $account);
        $this->ensureBelongsToAccount($account, $accountApiToken);
        $accountApiToken->update(['is_active' => false]);

        return redirect()->route('dashboard.accounts.brand.edit', [$account, 'tab' => 'api'])
            ->with('status', __('app.api_token_revoked'));
    }

    private function ensureBelongsToAccount(Account $account, AccountApiToken $accountApiToken): void
    {
        abort_unless($accountApiToken->account_id === $account->id, 404);
    }
}
