<?php

namespace App\Http\Controllers;

use App\Actions\UpdateUserProfile;
use App\Http\Requests\UpdateAccountOwnerProfileRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountOwnerProfileController extends Controller
{
    public function edit(Request $request, Account $account): View
    {
        $this->authorize('update', $account);

        return view('accounts.owner-profile-edit', [
            'account' => $account,
            'profileUser' => $request->user(),
        ]);
    }

    public function update(UpdateAccountOwnerProfileRequest $request, Account $account, UpdateUserProfile $updateUserProfile): RedirectResponse
    {
        $updateUserProfile->execute($request->user(), $request->validated(), $request->file('avatar'));

        return redirect()
            ->route('dashboard.accounts.owner-profile.edit', $account)
            ->with('status', __('app.profile_updated'));
    }
}
