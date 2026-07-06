<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\MobileAccountResource;
use App\Models\Account;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use Illuminate\Http\JsonResponse;

class MobileStudioController extends Controller
{
    public function show(string $accountSlug, CustomerAuthAvailability $availability): JsonResponse
    {
        $account = Account::active()
            ->with(['locations' => fn ($query) => $query->active()->orderBy('name')])
            ->where('slug', $accountSlug)
            ->firstOrFail();
        $methods = $availability->methodsFor($account);

        return response()->json([
            'data' => [
                'account' => new MobileAccountResource($account),
                'customer_auth' => [
                    'email_password' => $methods->emailPassword,
                    'otp' => $methods->otp,
                    'google' => $methods->google,
                    'turnstile_site_key' => $methods->turnstileSiteKey,
                    'google_redirect_url' => route('api.v1.mobile.auth.customer.google.redirect', $account->slug),
                ],
            ],
        ]);
    }
}
