<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Support\Telegram\CustomerTelegramLinkResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PublicStudioLandingController extends Controller
{
    public function __invoke(string $accountSlug, CustomerTelegramLinkResolver $telegramLinks): View
    {
        $account = Account::active()
            ->where('slug', $accountSlug)
            ->firstOrFail();

        $this->setAccountLocale($account);

        $locations = $account->locations()
            ->active()
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'slug', 'address', 'google_maps_embed_url']);
        $events = $account->events()
            ->published()
            ->upcoming()
            ->select(['id', 'account_id', 'slug', 'title', 'summary', 'starts_at', 'ends_at', 'timezone'])
            ->with(['media' => fn ($query) => $query
                ->select(['id', 'event_id', 'image_path', 'alt_text', 'sort_order', 'is_cover'])
                ->where('is_cover', true)])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(6)
            ->get();
        $festivals = collect();

        if ($account->enable_festivals) {
            $festivals = $account->festivalEditions()
                ->published()
                ->upcoming()
                ->select(['id', 'account_id', 'festival_series_id', 'slug', 'title', 'summary', 'starts_at', 'ends_at', 'timezone'])
                ->with('coverMedia')
                ->orderBy('starts_at')
                ->orderBy('id')
                ->limit(6)
                ->get();
        }

        return view('public.studio', [
            'account' => $account,
            'customer' => $this->currentCustomerFor($account),
            'locations' => $locations,
            'events' => $events,
            'festivals' => $festivals,
            'customerTelegramBotPublicStudioLink' => $telegramLinks->linkForPlacement(
                $account,
                CustomerTelegramLinkResolver::PlacementPublicStudio,
            ),
            'customerTelegramBotPublicContactsLink' => $telegramLinks->linkForPlacement(
                $account,
                CustomerTelegramLinkResolver::PlacementPublicContacts,
            ),
        ]);
    }

    private function currentCustomerFor(Account $account): ?Customer
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->account_id === $account->id ? $customer : null;
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }
}
