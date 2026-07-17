<?php

namespace App\Http\Controllers;

use App\Actions\BuildPublicPriceList;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PublicPriceController extends Controller
{
    public function show(Request $request, string $accountSlug, string $locationSlug, BuildPublicPriceList $buildPublicPriceList): View
    {
        return $this->render($request, $accountSlug, $locationSlug, $buildPublicPriceList, false);
    }

    public function embed(Request $request, string $accountSlug, string $locationSlug, BuildPublicPriceList $buildPublicPriceList): View
    {
        return $this->render($request, $accountSlug, $locationSlug, $buildPublicPriceList, true);
    }

    private function render(Request $request, string $accountSlug, string $locationSlug, BuildPublicPriceList $buildPublicPriceList, bool $isEmbed): View
    {
        [$account, $location] = $this->publicContext($accountSlug, $locationSlug);

        return view('public.price', [
            'account' => $account,
            'location' => $location,
            'priceGroups' => $buildPublicPriceList->execute($account, $location),
            'customer' => $this->currentCustomerFor($account),
            'isEmbed' => $isEmbed,
        ]);
    }

    /**
     * @return array{0: Account, 1: Location}
     */
    private function publicContext(string $accountSlug, string $locationSlug): array
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $this->setAccountLocale($account);

        $location = $account->locations()
            ->where('slug', $locationSlug)
            ->where('is_active', true)
            ->firstOrFail();

        return [$account, $location];
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }

    private function currentCustomerFor(Account $account): ?Customer
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->account_id === $account->id ? $customer : null;
    }
}
