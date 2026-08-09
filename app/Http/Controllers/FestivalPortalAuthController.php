<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\FestivalMagicLink;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class FestivalPortalAuthController extends Controller
{
    public function show(Request $request, string $accountSlug): View|RedirectResponse
    {
        $account = $this->account($request, $accountSlug);
        if ($request->user('festival')?->account_id === $account->id) {
            return redirect()->route('festival.portal.dashboard', $accountSlug);
        }

        return view('festivals.portal.login', compact('account'));
    }

    public function requestLink(Request $request, string $accountSlug, FestivalMagicLink $magicLink): RedirectResponse
    {
        $account = $this->account($request, $accountSlug);
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $magicLink->issue($account, $validated['email'], (string) $request->ip());

        return back()->with('status', __('app.festival_magic_link_generic'));
    }

    public function consume(Request $request, string $accountSlug, string $token, FestivalMagicLink $magicLink): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $account = $this->account($request, $accountSlug);
        $portalUser = $magicLink->consume($account, $token);
        Auth::guard('festival')->login($portalUser, true);
        $request->session()->regenerate();

        return redirect()->intended(route('festival.portal.dashboard', $accountSlug));
    }

    public function logout(Request $request, string $accountSlug): RedirectResponse
    {
        Auth::guard('festival')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('festival.login', $accountSlug);
    }

    private function account(Request $request, string $slug): Account
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $slug, 404);

        return $account;
    }
}
