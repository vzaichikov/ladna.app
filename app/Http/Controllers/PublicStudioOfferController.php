<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\PublicLegalDocumentReturnUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class PublicStudioOfferController extends Controller
{
    public function __invoke(Request $request, string $accountSlug, PublicLegalDocumentReturnUrl $returnUrl): View
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $this->setAccountLocale($account);

        return view('public.legal-document', [
            'account' => $account,
            'documentTitle' => __('app.public_offer'),
            'documentHtml' => $account->public_offer_html,
            'emptyMessage' => __('app.public_offer_empty'),
            'returnUrl' => $returnUrl->resolve($request, $account),
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
