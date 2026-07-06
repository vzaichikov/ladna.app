<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\MobileClassBookingResource;
use App\Http\Resources\MobileCustomerClassPassResource;
use App\Http\Resources\MobileCustomerResource;
use App\Models\MobileSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobileCustomerController extends Controller
{
    public function bookings(Request $request): JsonResponse
    {
        $session = $this->customerSession($request);
        $bookings = $session->customer->classBookings()
            ->notCorrectedRemoved()
            ->with(['scheduledClass.location', 'scheduledClass.room', 'scheduledClass.classType.activityDirection', 'scheduledClass.trainer', 'classPassReservation.customerClassPass'])
            ->whereHas('scheduledClass', fn (Builder $query): Builder => $query->where('account_id', $session->account_id))
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

    public function updateProfile(Request $request): JsonResponse
    {
        $session = $this->customerSession($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($session->customer_id)->where('account_id', $session->account_id)],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
        ]);

        if (($session->customer->phone ?? null) !== $validated['phone']) {
            $validated['phone_verified_at'] = null;
        }

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $session->customer->update($validated);

        return response()->json(['data' => new MobileCustomerResource($session->customer->refresh())]);
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

    private function staffSession(Request $request): MobileSession
    {
        $session = $request->attributes->get('mobileSession');

        abort_unless($session instanceof MobileSession && $session->guard === MobileSession::GuardStaff, 403);

        return $session;
    }
}
