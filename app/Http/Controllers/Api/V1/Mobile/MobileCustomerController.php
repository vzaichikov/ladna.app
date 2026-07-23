<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Actions\MergeCustomerIdentityByVerifiedPhone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\CustomerProfilePhoneOtpSendRequest;
use App\Http\Requests\Api\Mobile\CustomerProfilePhoneOtpVerifyRequest;
use App\Http\Resources\MobileAccountResource;
use App\Http\Resources\MobileClassBookingResource;
use App\Http\Resources\MobileCustomerClassPassResource;
use App\Http\Resources\MobileCustomerResource;
use App\Models\Customer;
use App\Models\MobileSession;
use App\Support\CustomerAuth\CustomerOtpService;
use App\Support\PhoneNumberNormalizer;
use App\Support\ScheduleKindRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileCustomerController extends Controller
{
    public function bookings(Request $request): JsonResponse
    {
        $session = $this->customerSession($request);
        $bookings = $session->customer->classBookings()
            ->notCorrectedRemoved()
            ->with(['scheduledClass.location', 'scheduledClass.room', 'scheduledClass.classType.activityDirection', 'scheduledClass.trainer', 'classPassReservation.customerClassPass'])
            ->whereHas('scheduledClass', fn (Builder $query): Builder => $query
                ->where('account_id', $session->account_id)
                ->whereHas('classType', fn (Builder $query): Builder => $query
                    ->whereIn('schedule_kind', ScheduleKindRegistry::customerBookableValues())))
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => MobileClassBookingResource::collection($bookings)]);
    }

    public function passes(Request $request): JsonResponse
    {
        $session = $this->customerSession($request);
        $passes = $session->customer->customerClassPasses()
            ->where('account_id', $session->account_id)
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => MobileCustomerClassPassResource::collection($passes)]);
    }

    public function updateProfile(Request $request, PhoneNumberNormalizer $phones): JsonResponse
    {
        $session = $this->customerSession($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($session->customer_id)->where('account_id', $session->account_id)],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
        ]);
        $validated['phone'] = $this->normalizePhone($session, (string) $validated['phone'], $phones);

        if ($this->duplicatePhoneCustomer($session, $validated['phone'])) {
            return response()->json([
                'message' => __('app.customer_profile_phone_merge_required'),
                'code' => 'phone_verification_required',
                'errors' => [
                    'phone' => [__('app.customer_profile_phone_merge_required')],
                ],
                'data' => [
                    'phone' => $validated['phone'],
                ],
            ], 422);
        }

        if (($session->customer->phone ?? null) !== $validated['phone']) {
            $validated['phone_verified_at'] = null;
        }

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $session->customer->update($validated);

        return response()->json(['data' => new MobileCustomerResource($session->customer->refresh())]);
    }

    public function sendProfilePhoneOtp(
        CustomerProfilePhoneOtpSendRequest $request,
        CustomerOtpService $otp,
        PhoneNumberNormalizer $phones,
    ): JsonResponse {
        $session = $this->customerSession($request);
        $validated = $request->validated();
        $phone = $this->normalizePhone($session, (string) $validated['phone'], $phones);

        if (! $this->duplicatePhoneCustomer($session, $phone)) {
            throw ValidationException::withMessages([
                'phone' => __('app.customer_auth_phone_invalid'),
            ]);
        }

        $result = $otp->send(
            $session->account,
            $phone,
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

    public function resendProfilePhoneOtp(
        CustomerProfilePhoneOtpSendRequest $request,
        CustomerOtpService $otp,
        PhoneNumberNormalizer $phones,
    ): JsonResponse {
        return $this->sendProfilePhoneOtp($request, $otp, $phones);
    }

    public function verifyProfilePhoneOtp(
        CustomerProfilePhoneOtpVerifyRequest $request,
        CustomerOtpService $otp,
        PhoneNumberNormalizer $phones,
        MergeCustomerIdentityByVerifiedPhone $mergeCustomer,
    ): JsonResponse {
        $session = $this->customerSession($request);
        $validated = $request->validated();
        $phone = $this->normalizePhone($session, (string) $validated['phone'], $phones);

        if (! $this->duplicatePhoneCustomer($session, $phone)) {
            throw ValidationException::withMessages([
                'phone' => __('app.customer_auth_phone_invalid'),
            ]);
        }

        $result = $otp->verify($session->account, $phone, (string) $validated['code']);

        if (! $result->ok || ! $result->challenge) {
            throw ValidationException::withMessages([
                'code' => $result->message ?? __('app.customer_otp_invalid'),
            ]);
        }

        $customer = $mergeCustomer->execute($session->account, $result->challenge->phone, $session->customer, [
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'email_verified' => $session->customer->email === ($validated['email'] ?? null) && $session->customer->email_verified_at !== null,
            'password' => $validated['password'] ?? null,
        ]);

        $session->forceFill(['customer_id' => $customer->id])->save();
        $session->setRelation('customer', $customer);

        return response()->json([
            'message' => __('app.customer_profile_phone_verified'),
            'data' => [
                'account' => new MobileAccountResource($session->account->loadMissing('locations')),
                'actor' => [
                    'type' => MobileSession::GuardCustomer,
                    'customer' => new MobileCustomerResource($customer),
                ],
                'token' => (string) $request->bearerToken(),
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $session = $this->staffSession($request);

        abort_unless($session->account->userCan($session->user, 'manage_bookings') || $session->account->userCan($session->user, 'manage_clients'), 403);

        $search = trim((string) $request->query('q'));
        $customers = $session->account->customers()
            ->when($search !== '', function (Builder $query) use ($search): Builder {
                return $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json(['data' => MobileCustomerResource::collection($customers)]);
    }

    private function customerSession(Request $request): MobileSession
    {
        $session = $request->attributes->get('mobileSession');

        abort_unless($session instanceof MobileSession && $session->guard === MobileSession::GuardCustomer, 403);

        return $session;
    }

    private function normalizePhone(MobileSession $session, string $phone, PhoneNumberNormalizer $phones): string
    {
        $normalizedPhone = $phones->normalize($phone, $session->account->country_code ?? 'UA');

        if (! $normalizedPhone || ! $phones->isValid($normalizedPhone, $session->account->country_code ?? 'UA')) {
            throw ValidationException::withMessages([
                'phone' => __('app.customer_auth_phone_invalid'),
            ]);
        }

        return $normalizedPhone;
    }

    private function duplicatePhoneCustomer(MobileSession $session, string $phone): ?Customer
    {
        return $session->account->customers()
            ->where('phone', $phone)
            ->whereKeyNot($session->customer_id)
            ->first();
    }

    private function staffSession(Request $request): MobileSession
    {
        $session = $request->attributes->get('mobileSession');

        abort_unless($session instanceof MobileSession && $session->guard === MobileSession::GuardStaff, 403);

        return $session;
    }
}
