<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\StoreFestivalSubmission;
use App\Http\Requests\FestivalSubmissionRequest;
use App\Models\Account;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalPortalUser;
use Illuminate\Http\RedirectResponse;

class FestivalSubmissionController extends Controller
{
    public function store(FestivalSubmissionRequest $request, string $accountSlug, FestivalEntry $festivalEntry, FestivalEntryRequirement $festivalEntryRequirement, StoreFestivalSubmission $store): RedirectResponse
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $portalUser instanceof FestivalPortalUser, 404);
        abort_unless($festivalEntry->account_id === $account->id && $festivalEntry->festival_portal_user_id === $portalUser->id && $festivalEntryRequirement->festival_entry_id === $festivalEntry->id, 404);
        $store->execute($festivalEntryRequirement, $portalUser, $request->file('file'));

        return back()->with('status', __('app.festival_submission_saved'));
    }
}
