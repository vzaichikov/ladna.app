<?php

namespace App\Http\Controllers;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalPortalUser;
use App\Models\User;
use App\Support\FestivalAuth\AdminFestivalLoginTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

class AdminFestivalLoginController extends Controller
{
    public function store(
        Request $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalPortalUser $festivalPortalUser,
        AdminFestivalLoginTokenService $tokens,
    ): RedirectResponse {
        $this->authorize('view', $account);
        $user = $request->user('web');

        abort_unless($user instanceof User && $account->isOwnedBy($user), 403);
        abort_unless((int) $festivalEdition->account_id === (int) $account->id, 404);
        abort_unless((int) $festivalPortalUser->account_id === (int) $account->id
            && $festivalPortalUser->role === FestivalPortalRole::Registrant
            && $festivalPortalUser->is_active, 404);

        return redirect()->away($tokens->issueUrl($account, $festivalPortalUser, $user));
    }

    public function consume(
        Request $request,
        string $accountSlug,
        string $token,
        AdminFestivalLoginTokenService $tokens,
    ): RedirectResponse {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        abort_if($account->isReadOnlyDemo(), 404);
        $portalUser = $tokens->consume($account, $token);
        abort_unless($portalUser instanceof FestivalPortalUser, 404);

        if (! $request->session()->has('locale')) {
            App::setLocale($account->default_language);
        }

        Auth::guard('festival')->logout();
        Auth::guard('festival')->login($portalUser);
        $request->session()->regenerate();
        $portalUser->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('festival.portal.dashboard', $account->slug);
    }
}
