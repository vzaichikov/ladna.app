<?php

namespace App\Http\Controllers;

use App\Actions\BuildStudioPublicListings;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Telegram\CustomerTelegramLinkResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PublicStudioLandingController extends Controller
{
    public function __invoke(
        string $accountSlug,
        CustomerTelegramLinkResolver $telegramLinks,
        BuildStudioPublicListings $buildListings,
    ): View {
        $account = Account::active()
            ->where('slug', $accountSlug)
            ->firstOrFail();

        $this->setAccountLocale($account);

        $locations = $account->locations()
            ->active()
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'slug', 'address', 'google_maps_embed_url']);

        return view('public.studio', [
            'account' => $account,
            'customer' => $this->currentCustomerFor($account),
            'locations' => $locations,
            ...$buildListings($account),
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
