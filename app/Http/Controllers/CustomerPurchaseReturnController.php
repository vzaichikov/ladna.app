<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class CustomerPurchaseReturnController extends Controller
{
    public function __invoke(string $accountSlug, CustomerPurchase $customerPurchase): RedirectResponse
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer || $customer->account_id !== $account->id) {
            return redirect()->route('customer.studio.login', $account->slug);
        }

        abort_unless($customerPurchase->account_id === $account->id && $customerPurchase->customer_id === $customer->id, 404);

        return redirect()
            ->route('customer.dashboard', $account->slug)
            ->with('status', $customerPurchase->fresh()->isPaid()
                ? __('app.payment_completed')
                : __('app.payment_processing'));
    }
}
