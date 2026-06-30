<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class PublicStudioLandingController extends Controller
{
    public function __invoke(string $accountSlug): View
    {
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
            'locations' => $locations,
        ]);
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }
}
