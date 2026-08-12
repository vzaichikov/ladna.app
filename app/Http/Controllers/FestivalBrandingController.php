<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalBranding;
use App\Http\Requests\UpdateFestivalBrandingRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use Illuminate\Http\RedirectResponse;

class FestivalBrandingController extends Controller
{
    public function update(
        UpdateFestivalBrandingRequest $request,
        Account $account,
        FestivalEdition $festivalEdition,
        SaveFestivalBranding $save,
    ): RedirectResponse {
        $save->execute(
            $account,
            $festivalEdition,
            $request->brandingPayload(),
            $request->user(),
            $request->file('hero_image'),
        );

        return redirect()
            ->route('dashboard.accounts.festivals.edit', [$account, $festivalEdition, 'tab' => 'branding'])
            ->with('status', __('app.festival_branding_saved'));
    }
}
