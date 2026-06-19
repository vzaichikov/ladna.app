<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountOwnerProfileRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class AccountOwnerProfileController extends Controller
{
    public function update(UpdateAccountOwnerProfileRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $user->fill(collect($validated)->except(['avatar', 'password', 'password_confirmation'])->all());

        if (filled($validated['password'] ?? null)) {
            $user->password = $validated['password'];
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $user->avatar_path = $request->file('avatar')->store('user-avatars/'.$user->id, 'public');
        }

        $user->save();

        return redirect()
            ->route('dashboard.accounts.edit', [$account, 'tab' => 'account'])
            ->with('status', __('app.profile_updated'));
    }
}
