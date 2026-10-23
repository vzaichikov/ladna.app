<?php

namespace App\View\Composers;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSponsor;
use App\Models\FestivalTicketOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class FestivalSponsorComposer
{
    public function compose(View $view): void
    {
        $view->with('festivalSponsors', collect());
        $view->with('festivalFooterSponsors', collect());
        $request = request();
        $data = $view->getData();
        $account = $data['account'] ?? $request->route('account');

        if (($data['isEmbed'] ?? false)
            || ! $account instanceof Account
            || ! $account->exists
            || ! $account->enable_festivals
            || ! $this->accountMatchesRoute($request, $account)) {
            return;
        }

        $edition = $this->edition($request, $account, $data);

        if (! $edition instanceof FestivalEdition || ! $edition->exists || $edition->account_id !== $account->id) {
            return;
        }

        $isLanding = $request->routeIs('public.festivals.show');
        $cacheKey = 'festival.sponsors.'.$account->id.'.'.$edition->id;
        $sponsors = $request->attributes->get($cacheKey);

        if (! $sponsors instanceof Collection) {
            $sponsors = FestivalSponsor::query()
                ->where('account_id', $account->id)
                ->where('festival_edition_id', $edition->id)
                ->when(! $isLanding, fn (Builder $query): Builder => $query->where('show_on_all_pages', true))
                ->orderBy('id')
                ->get();
            $request->attributes->set($cacheKey, $sponsors);
        }

        $view->with('festivalSponsors', $isLanding ? $sponsors : collect());
        $view->with('festivalFooterSponsors', $sponsors->where('show_on_all_pages', true)->values());
    }

    private function accountMatchesRoute(Request $request, Account $account): bool
    {
        $routeAccount = $request->route('account');
        $accountSlug = $request->route('accountSlug');

        return ($routeAccount === null || ($routeAccount instanceof Account && $routeAccount->is($account)))
            && ($accountSlug === null || $accountSlug === $account->slug);
    }

    /** @param array<string, mixed> $data */
    private function edition(Request $request, Account $account, array $data): ?FestivalEdition
    {
        if ($request->routeIs('dashboard.accounts.festivals.*', 'festival.portal.*')) {
            $edition = $request->route('festivalEdition');

            if ($edition instanceof FestivalEdition) {
                return $edition;
            }

            $entry = $request->route('festivalEntry');

            if ($entry instanceof FestivalEntry) {
                return $entry->account_id === $account->id ? $entry->loadMissing('edition')->edition : null;
            }

            $sheet = $request->route('festivalScoreSheet');

            if ($sheet instanceof FestivalScoreSheet && $sheet->account_id === $account->id) {
                $sheet->loadMissing('entry.edition');

                return $sheet->entry?->account_id === $account->id ? $sheet->entry->edition : null;
            }
        }

        if ($request->routeIs('public.festivals.show', 'public.festivals.entrance', 'festival.portal.entries.create', 'festival.portal.battle-votes.index')) {
            $edition = $data['edition'] ?? null;

            return $edition instanceof FestivalEdition && $edition->slug === $request->route('editionSlug') ? $edition : null;
        }

        if ($request->routeIs('public.festival-orders.show', 'public.festival-orders.payment')) {
            $order = $data['order'] ?? null;

            if (! $order instanceof FestivalTicketOrder
                || $order->account_id !== $account->id
                || ! hash_equals((string) $order->access_token_hash, hash('sha256', (string) $request->route('accessToken')))) {
                return null;
            }

            return $order->loadMissing('edition')->edition;
        }

        if ($request->routeIs('festival.stream.player')) {
            $stream = $data['stream'] ?? null;

            if ($stream instanceof FestivalOnlineStream
                && $stream->account_id === $account->id
                && $stream->path === $request->route('path')) {
                return $stream->loadMissing('edition')->edition;
            }
        }

        return null;
    }
}
