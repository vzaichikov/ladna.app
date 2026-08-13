<?php

namespace App\Http\Controllers;

use App\Support\Festivals\FestivalPoweredBannerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DismissFestivalPoweredBannerController extends Controller
{
    public function __invoke(
        Request $request,
        FestivalPoweredBannerSettings $settings,
    ): RedirectResponse {
        return back()->withCookie($settings->dismissalCookie($request));
    }
}
