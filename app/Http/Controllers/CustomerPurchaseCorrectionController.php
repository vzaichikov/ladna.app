<?php

namespace App\Http\Controllers;

use App\Actions\CorrectCustomerPurchase;
use App\Http\Requests\CorrectCustomerPurchaseRequest;
use App\Models\Account;
use App\Models\CustomerPurchase;
use Illuminate\Http\RedirectResponse;

class CustomerPurchaseCorrectionController extends Controller
{
    public function store(CorrectCustomerPurchaseRequest $request, Account $account, CustomerPurchase $customerPurchase, CorrectCustomerPurchase $correctCustomerPurchase): RedirectResponse
    {
        abort_unless($customerPurchase->account_id === $account->id, 404);

        $location = $account->locations()->whereKey($request->validated('location_id'))->firstOrFail();

        $correctCustomerPurchase->execute(
            $account,
            $customerPurchase,
            $location,
            $request->amountCents(),
            $request->paidAt(),
            $request->user(),
            $request->validated('reason'),
        );

        return back()->with('status', __('app.payment_correction_saved'));
    }
}
