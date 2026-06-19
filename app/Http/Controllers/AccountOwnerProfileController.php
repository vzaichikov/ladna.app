<?php

namespace App\Http\Controllers;

use App\Actions\UpdateUserProfile;
use App\Http\Requests\UpdateAccountOwnerProfileRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;

class AccountOwnerProfileController extends Controller
{
    public function update(UpdateAccountOwnerProfileRequest $request, Account $account, UpdateUserProfile $updateUserProfile): RedirectResponse
    {
        $updateUserProfile->execute($request->user(), $request->validated(), $request->file('avatar'));

        return redirect()
            ->route('dashboard.accounts.edit', [$account, 'tab' => 'account'])
            ->with('status', __('app.profile_updated'));
    }
}
