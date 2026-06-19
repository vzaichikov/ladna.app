<?php

namespace App\Http\Controllers\Platform;

use App\Actions\UpdateUserProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePlatformProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('platform.profile.edit', [
            'profileUser' => request()->user(),
        ]);
    }

    public function update(UpdatePlatformProfileRequest $request, UpdateUserProfile $updateUserProfile): RedirectResponse
    {
        $updateUserProfile->execute($request->user(), $request->validated(), $request->file('avatar'));

        return redirect()
            ->route('platform.account.edit')
            ->with('status', __('app.profile_updated'));
    }
}
