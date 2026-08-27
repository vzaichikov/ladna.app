<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use App\Support\FestivalAuth\TelegramFestivalLoginTokenService;
use App\Support\Festivals\FestivalTelegramCheckoutHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FestivalTelegramLoginController extends Controller
{
    public function consume(
        Request $request,
        string $accountSlug,
        string $seriesSlug,
        string $token,
        TelegramFestivalLoginTokenService $tokens,
    ): RedirectResponse {
        [$account, $series] = $this->context($request, $accountSlug, $seriesSlug);
        $login = $tokens->consumeRegistrant($series, $token);
        abort_unless($login, 403);

        Auth::guard('festival')->login($login['portal_user'], true);
        $request->session()->regenerate();
        $login['portal_user']->forceFill(['last_login_at' => now()])->save();

        return redirect()->route($login['route_name'], $login['route_parameters']);
    }

    public function order(
        Request $request,
        string $accountSlug,
        string $seriesSlug,
        string $token,
        TelegramFestivalLoginTokenService $tokens,
    ): RedirectResponse {
        [$account, $series] = $this->context($request, $accountSlug, $seriesSlug);
        $order = $tokens->consumeOrder($series, $token);
        abort_unless($order, 403);

        return redirect()->route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]);
    }

    public function checkout(
        Request $request,
        string $accountSlug,
        string $seriesSlug,
        string $editionSlug,
        string $token,
        FestivalTelegramCheckoutHandoff $handoff,
    ): RedirectResponse {
        [$account, $series] = $this->context($request, $accountSlug, $seriesSlug);
        $edition = FestivalEdition::query()
            ->where('account_id', $account->id)
            ->where('festival_series_id', $series->id)
            ->where('slug', $editionSlug)
            ->published()
            ->firstOrFail();
        abort_unless($handoff->consumeIntoSession($request, $series, $edition, $token), 403);

        return redirect()->to(route('public.festivals.show', [$account->slug, $edition->slug]).'#festival-admission');
    }

    /** @return array{Account, FestivalSeries} */
    private function context(Request $request, string $accountSlug, string $seriesSlug): array
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $accountSlug, 404);
        $series = FestivalSeries::query()
            ->whereBelongsTo($account)
            ->where('slug', $seriesSlug)
            ->where('is_active', true)
            ->firstOrFail();

        return [$account, $series];
    }
}
