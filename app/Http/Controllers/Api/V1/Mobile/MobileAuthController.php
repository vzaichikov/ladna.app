<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\CustomerEmailLoginRequest;
use App\Http\Requests\Api\Mobile\CustomerOtpSendRequest;
use App\Http\Requests\Api\Mobile\CustomerOtpVerifyRequest;
use App\Http\Requests\Api\Mobile\StaffLoginRequest;
use App\Http\Resources\MobileAccountResource;
use App\Models\Account;
use App\Models\Customer;
use App\Models\MobileSession;
use App\Models\User;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\CustomerAuth\CustomerOtpService;
use App\Support\CustomerAuth\TurnstileVerifier;
use App\Support\Mobile\MobileGoogleOAuthBridge;
use App\Support\Mobile\MobileProfilePayload;
use App\Support\Mobile\MobileSessionIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MobileAuthController extends Controller
{
    public function staffLogin(StaffLoginRequest $request, MobileSessionIssuer $issuer): JsonResponse
    {
        $validated = $request->validated();
        $user = User::with(['accountMemberships.account.locations'])
            ->where('email', $validated['email'])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('app.auth_failed'),
            ]);
        }

        if ($user->isPlatformAdmin()) {
            return response()->json([
                'message' => __('app.api_token_forbidden'),
            ], 403);
        }

        $memberships = $user->accountMemberships
            ->filter(fn ($membership): bool => $membership->account !== null && $membership->account->status->value === 'active')
            ->values();

        if ($memberships->isEmpty()) {
            return response()->json([
                'message' => __('app.api_token_forbidden'),
            ], 403);
        }

        $accounts = $memberships->map(function ($membership) use ($issuer, $user, $validated): array {
            $session = $issuer->issueForStaff(
                $membership->account,
                $user,
                $membership->role->value,
                $validated['device_name'] ?? null,
                $validated['platform'] ?? null,
            );

            return [
                'account' => new MobileAccountResource($membership->account),
                'role' => $membership->role->value,
                'token' => $session->getAttribute('plain_token'),
                'expires_at' => $session->expires_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'data' => [
                'actor' => [
                    'type' => MobileSession::GuardStaff,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
                'accounts' => $accounts,
            ],
        ]);
    }

    public function customerEmailLogin(CustomerEmailLoginRequest $request, CustomerAuthAvailability $availability, MobileSessionIssuer $issuer): JsonResponse
    {
        $validated = $request->validated();
        $account = $this->account((string) $validated['account_slug']);
        $methods = $availability->methodsFor($account);

        abort_unless($methods->emailPassword, 404);

        $customer = $account->customers()->where('email', $validated['email'])->first();

        if ($customer) {
            if (blank($customer->password)) {
                throw ValidationException::withMessages([
                    'email' => __('app.customer_password_not_available'),
                ]);
            }

            if (! Hash::check($validated['password'], $customer->password)) {
                throw ValidationException::withMessages([
                    'email' => __('app.auth_failed'),
                ]);
            }
        } else {
            $customer = $account->customers()->create([
                'email' => $validated['email'],
                'password' => $validated['password'],
                'default_language' => $account->default_language,
            ]);
        }

        return $this->customerSessionResponse($account, $customer, $issuer, $validated);
    }

    public function customerOtpSend(
        CustomerOtpSendRequest $request,
        CustomerAuthAvailability $availability,
        TurnstileVerifier $turnstile,
        CustomerOtpService $otp,
    ): JsonResponse {
        $validated = $request->validated();
        $account = $this->account((string) $validated['account_slug']);
        $methods = $availability->methodsFor($account);

        abort_unless($methods->otp, 404);

        $turnstileSetting = $availability->turnstileSetting();
        $turnstileToken = (string) ($validated['turnstile_token'] ?? '');

        if (! $turnstileSetting || $turnstileToken === '' || ! $turnstile->verify($turnstileToken, (string) $request->ip(), $turnstileSetting->readableCredentials())) {
            throw ValidationException::withMessages([
                'turnstile_token' => __('app.customer_captcha_failed'),
            ]);
        }

        $result = $otp->send(
            $account,
            (string) $validated['phone'],
            (string) $request->ip(),
            substr((string) $request->userAgent(), 0, 1000),
        );

        if (! $result->ok) {
            throw ValidationException::withMessages([
                'phone' => $result->message ?? __('app.customer_otp_send_failed'),
            ]);
        }

        return response()->json([
            'message' => __('app.customer_otp_sent'),
            'data' => [
                'phone' => $result->challenge?->phone,
                'resend_seconds' => $result->secondsUntilResend,
            ],
        ]);
    }

    public function customerOtpVerify(CustomerOtpVerifyRequest $request, CustomerOtpService $otp, MobileSessionIssuer $issuer): JsonResponse
    {
        $validated = $request->validated();
        $account = $this->account((string) $validated['account_slug']);
        $result = $otp->verify($account, (string) $validated['phone'], (string) $validated['code']);

        if (! $result->ok || ! $result->challenge) {
            throw ValidationException::withMessages([
                'code' => $result->message ?? __('app.customer_otp_invalid'),
            ]);
        }

        $customer = $account->customers()->firstOrCreate(
            ['phone' => $result->challenge->phone],
            [
                'phone_verified_at' => now(),
                'default_language' => $account->default_language,
            ],
        );
        $customer->forceFill([
            'phone_verified_at' => $customer->phone_verified_at ?? now(),
        ])->save();

        return $this->customerSessionResponse($account, $customer, $issuer, $validated);
    }

    public function customerGoogleRedirect(Request $request, string $accountSlug, MobileGoogleOAuthBridge $bridge): RedirectResponse
    {
        return $bridge->redirect(
            $this->account($accountSlug),
            $request->filled('return_url') ? (string) $request->query('return_url') : null,
        );
    }

    public function customerGoogleCallback(Request $request, MobileGoogleOAuthBridge $bridge): RedirectResponse
    {
        return $bridge->callback($request);
    }

    public function customerGoogleExchange(Request $request, MobileGoogleOAuthBridge $bridge, MobileSessionIssuer $issuer): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'in:android,ios'],
        ]);

        try {
            $loginCode = $bridge->consumeLoginCode((string) $validated['code']);
        } catch (HttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'demo_readonly',
            ], $exception->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => __('app.api_token_invalid')], 401);
        }

        return $this->customerSessionResponse($loginCode->account, $loginCode->customer, $issuer, $validated);
    }

    public function me(Request $request, MobileProfilePayload $payload): JsonResponse
    {
        return response()->json([
            'data' => $payload->forSession($this->session($request)),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->session($request)->forceFill(['revoked_at' => now()])->save();

        return response()->json(['message' => 'OK']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function customerSessionResponse(Account $account, Customer $customer, MobileSessionIssuer $issuer, array $validated): JsonResponse
    {
        $session = $issuer->issueForCustomer(
            $account,
            $customer,
            $validated['device_name'] ?? null,
            $validated['platform'] ?? null,
        );

        return response()->json([
            'data' => [
                'account' => new MobileAccountResource($account->loadMissing('locations')),
                'actor' => [
                    'type' => MobileSession::GuardCustomer,
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                        'profile_complete' => $customer->profileIsComplete(),
                    ],
                ],
                'token' => $session->getAttribute('plain_token'),
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ]);
    }

    private function account(string $accountSlug): Account
    {
        return Account::active()
            ->with('locations')
            ->where('slug', $accountSlug)
            ->firstOrFail();
    }

    private function session(Request $request): MobileSession
    {
        return $request->attributes->get('mobileSession');
    }
}
