<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\PublishFestivalResults;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FestivalResultController extends Controller
{
    public function publish(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalCategory $festivalCategory, PublishFestivalResults $publish): RedirectResponse
    {
        abort_unless($festivalEdition->account_id === $account->id && $festivalCategory->festival_edition_id === $festivalEdition->id, 404);
        abort_unless($request->user()?->can('manageFestivals', $account), 403);
        $count = $publish->execute($festivalEdition, $festivalCategory, $request->user());

        return redirect()->route('dashboard.accounts.festivals.judging.index', [$account, $festivalEdition])->with('status', __('app.festival_results_published', ['count' => $count]));
    }
}
