<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateAccountFestivalCapabilityRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;

class AccountFestivalCapabilityController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(UpdateAccountFestivalCapabilityRequest $request, Account $account): RedirectResponse
    {
        $account->forceFill(['enable_festivals' => $request->boolean('enable_festivals')])->save();

        return redirect()->route('platform.accounts.show', $account)
            ->with('status', __('app.festival_capability_updated'));
    }
}
