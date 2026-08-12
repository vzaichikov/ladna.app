<?php

namespace App\Http\Controllers;

use App\Http\Requests\FestivalParticipantRequest;
use App\Models\Account;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FestivalParticipantController extends Controller
{
    public function index(Request $request, string $accountSlug): View
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $participants = $portalUser->participants()->whereNull('archived_at')->orderBy('last_name')->orderBy('first_name')->get();

        return view('festivals.portal.participants', compact('account', 'portalUser', 'participants'));
    }

    public function store(FestivalParticipantRequest $request, string $accountSlug): RedirectResponse
    {
        [$account, $portalUser] = $this->context($request, $accountSlug);
        $portalUser->participants()->create(['account_id' => $account->id, ...$request->validated()]);

        return back()->with('status', __('app.festival_portal_team_saved'));
    }

    public function update(FestivalParticipantRequest $request, string $accountSlug, FestivalParticipant $festivalParticipant): RedirectResponse
    {
        [, $portalUser] = $this->context($request, $accountSlug);
        abort_unless($festivalParticipant->festival_portal_user_id === $portalUser->id && $festivalParticipant->account_id === $portalUser->account_id, 404);
        abort_if($festivalParticipant->is_profile_owner, 409);
        $festivalParticipant->update($request->validated());

        return back()->with('status', __('app.festival_portal_team_saved'));
    }

    public function destroy(Request $request, string $accountSlug, FestivalParticipant $festivalParticipant): RedirectResponse
    {
        [, $portalUser] = $this->context($request, $accountSlug);
        abort_unless($festivalParticipant->festival_portal_user_id === $portalUser->id && $festivalParticipant->account_id === $portalUser->account_id, 404);
        abort_if($festivalParticipant->is_profile_owner, 409);
        abort_if($festivalParticipant->newQuery()->whereKey($festivalParticipant->id)->whereHas('entries', fn ($query) => $query->where('status', '!=', 'draft'))->exists(), 409);
        $festivalParticipant->forceFill(['archived_at' => now()])->save();

        return back()->with('status', __('app.festival_portal_removed_from_team'));
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
