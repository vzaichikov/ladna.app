<?php

namespace App\Http\Controllers;

use App\Enums\AccountSignupStatus;
use App\Models\AccountSignupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicDemoSignupController extends Controller
{
    public function returned(Request $request, AccountSignupRequest $accountSignupRequest): RedirectResponse
    {
        $accountSignupRequest->loadMissing('account');

        if ($accountSignupRequest->account) {
            $status = $accountSignupRequest->status === AccountSignupStatus::AccountCreated
                ? __('app.demo_signup_payment_completed')
                : __('app.payment_processing');

            if ($request->user() && $accountSignupRequest->account->isAccessibleBy($request->user())) {
                return redirect()
                    ->route('dashboard.accounts.tariff-payments.show', $accountSignupRequest->account)
                    ->with('status', $status);
            }

            return redirect()
                ->route('login')
                ->with('status', $status);
        }

        return redirect()
            ->route('login')
            ->with('status', __('app.payment_processing'));
    }
}
