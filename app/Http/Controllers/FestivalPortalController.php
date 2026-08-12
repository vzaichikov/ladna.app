<?php

namespace App\Http\Controllers;

use App\Enums\FestivalPortalRole;
use App\Http\Requests\FestivalPortalProfileRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalNotification;
use App\Models\FestivalPortalUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalPortalController extends Controller
{
    public function dashboard(Request $request, string $accountSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $editions = FestivalEdition::query()->whereBelongsTo($account)->published()->with(['series', 'scheduleSlots' => fn ($query) => $query->whereNotNull('published_at')->whereHas('entry', fn ($query) => $query->where('festival_portal_user_id', $portalUser->id))->with(['stage', 'entry'])])->orderBy('starts_at')->get();
        $entries = $portalUser->entries()->with(['edition', 'category', 'steps', 'requirements', 'charges', 'result'])->latest()->get();
        $notifications = FestivalNotification::query()->where('festival_portal_user_id', $portalUser->id)->latest()->limit(50)->get();

        return view('festivals.portal.dashboard', compact('account', 'portalUser', 'editions', 'entries', 'notifications'));
    }

    public function judgeDashboard(Request $request, string $accountSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        abort_unless($portalUser->role === FestivalPortalRole::Judge, 403);
        $assignments = $portalUser->judgeAssignments()
            ->where('is_active', true)
            ->with(['edition.series', 'categories'])
            ->whereHas('edition', fn ($query) => $query->where('account_id', $account->id))
            ->latest('id')
            ->get();

        return view('festivals.portal.judge-dashboard', compact('account', 'portalUser', 'assignments'));
    }

    public function editProfile(Request $request, string $accountSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);

        return view('festivals.portal.profile', compact('account', 'portalUser'));
    }

    public function updateProfile(FestivalPortalProfileRequest $request, string $accountSlug): RedirectResponse
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if ($data['email_normalized'] !== $portalUser->email_normalized) {
            $data['email_verified_at'] = null;
        }

        if ($data['phone_normalized'] !== $portalUser->phone_normalized) {
            $data['phone_verified_at'] = null;
        }

        $portalUser->update($data);
        $request->session()->put('locale', $portalUser->locale);

        return redirect()->route(
            $portalUser->role === FestivalPortalRole::Judge ? 'festival.portal.judge.dashboard' : 'festival.portal.dashboard',
            $account->slug,
        )->with('status', __('app.festival_profile_saved'));
    }

    /** @return array{Account, FestivalPortalUser} */
    private function context(Request $request, string $slug): array
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $slug && $portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id, 404);

        return [$account, $portalUser];
    }
}
