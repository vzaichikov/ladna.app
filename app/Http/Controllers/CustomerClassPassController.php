<?php

namespace App\Http\Controllers;

use App\Actions\AdjustCustomerClassPassSessions;
use App\Actions\AdjustCustomerClassPassValidityDays;
use App\Actions\FreezeCustomerClassPass;
use App\Actions\IssueCustomerClassPass;
use App\Actions\NormalizeCustomerClassPasses;
use App\Actions\ReconcileUnreservedCustomerBookingsForIssuedClassPass;
use App\Actions\RecordManualCustomerClassPassPayment;
use App\Actions\UnfreezeCustomerClassPass;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Http\Requests\StoreCustomerClassPassAdjustmentRequest;
use App\Http\Requests\StoreCustomerClassPassPaymentRequest;
use App\Http\Requests\StoreCustomerClassPassRequest;
use App\Http\Requests\StoreCustomerClassPassValidityAdjustmentRequest;
use App\Http\Requests\UpdateCustomerClassPassRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Support\DateTimePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CustomerClassPassController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->authorize('manageCustomerClassPasses', $account);

        $term = trim((string) $request->query('q', ''));
        $requestedState = (string) $request->query('state', 'active');
        $state = in_array($requestedState, ['active', 'inactive', 'freezed', 'all'], true) ? $requestedState : 'active';
        $enabledScheduleKinds = $account->enabledScheduleKindValues();
        $requestedScheduleKind = (string) $request->query('schedule_kind', '');
        $scheduleKind = in_array($requestedScheduleKind, $enabledScheduleKinds, true) ? $requestedScheduleKind : '';
        $requestedPaymentStatus = (string) $request->query('payment_status', '');
        $paymentStatus = in_array($requestedPaymentStatus, ['paid', 'partial', 'unpaid'], true) ? $requestedPaymentStatus : '';
        $unpaidActiveClassPassesCount = $account->customerClassPasses()
            ->active()
            ->unpaid()
            ->count();
        $partialActiveClassPassesCount = $account->customerClassPasses()
            ->active()
            ->partiallyPaid()
            ->count();

        $customerClassPasses = $account->customerClassPasses()
            ->with(['customer', 'issuedLocation', 'classPassPlan.classTypes', 'classPassPlan.trainerTypes', 'classPassPlan.rooms'])
            ->when($term !== '', function ($query) use ($term): void {
                $query->where(function ($query) use ($term): void {
                    $query->where('code', 'like', "%{$term}%")
                        ->orWhereHas('customer', function ($query) use ($term): void {
                            $query->where('name', 'like', "%{$term}%")
                                ->orWhere('phone', 'like', "%{$term}%")
                                ->orWhere('email', 'like', "%{$term}%");
                        });
                });
            })
            ->when($state === 'active', fn ($query) => $query->active())
            ->when($state === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($state === 'freezed', fn ($query) => $query->freezed())
            ->when($paymentStatus === 'paid', fn ($query) => $query->paid())
            ->when($paymentStatus === 'partial', fn ($query) => $query->partiallyPaid())
            ->when($paymentStatus === 'unpaid', fn ($query) => $query->unpaid())
            ->when($scheduleKind !== '', function ($query) use ($scheduleKind): void {
                $query->whereHas('classPassPlan', fn ($query) => $query->where('schedule_kind', $scheduleKind));
            })
            ->orderByRaw('CASE WHEN opened_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('opened_at')
            ->orderByDesc('purchased_at')
            ->paginate(20)
            ->withQueryString();

        return view('customer-class-passes.index', [
            'account' => $account,
            'customerClassPasses' => $customerClassPasses,
            'state' => $state,
            'scheduleKind' => $scheduleKind,
            'paymentStatus' => $paymentStatus,
            'enabledScheduleKinds' => $enabledScheduleKinds,
            'unpaidActiveClassPassesCount' => $unpaidActiveClassPassesCount,
            'partialActiveClassPassesCount' => $partialActiveClassPassesCount,
        ]);
    }

    public function store(StoreCustomerClassPassRequest $request, Account $account, Customer $customer, IssueCustomerClassPass $issueCustomerClassPass): RedirectResponse
    {
        $this->ensureCustomerBelongsToAccount($account, $customer);
        $classPassPlan = $account->classPassPlans()->whereKey($request->validated('class_pass_plan_id'))->firstOrFail();
        $issuedLocation = $account->locations()->whereKey($request->validated('issued_location_id'))->firstOrFail();

        $issueCustomerClassPass->execute(
            $account,
            $customer,
            $classPassPlan,
            issuedBy: $request->user(),
            issuedLocation: $issuedLocation,
            isPaid: $request->boolean('is_paid'),
            paidAmountCents: $request->paidAmountCents(),
        );

        return redirect()->route('dashboard.accounts.customers.edit', [$account, $customer])
            ->with('status', __('app.customer_class_pass_issued'));
    }

    public function backfill(Account $account, Customer $customer, ReconcileUnreservedCustomerBookingsForIssuedClassPass $reconcileUnreservedCustomerBookings): RedirectResponse
    {
        $this->ensureCustomerBelongsToAccount($account, $customer);
        $this->authorize('manageCustomerClassPasses', $account);

        $preview = $reconcileUnreservedCustomerBookings->previewForCustomer($customer);
        $reconcileUnreservedCustomerBookings->executeForCustomer($customer);

        return redirect()->route('dashboard.accounts.customers.edit', [$account, $customer])
            ->with('status', __('app.customer_class_pass_backfill_applied', [
                'used' => $preview['totals']['used'],
                'reserved' => $preview['totals']['reserved'],
            ]));
    }

    public function edit(Account $account, CustomerClassPass $customerClassPass): View
    {
        $this->ensureBelongsToAccount($account, $customerClassPass);
        $this->authorize('manageCustomerClassPasses', $account);

        $customerClassPass->load(['customer', 'issuedLocation', 'classPassPlan.classTypes', 'reservations.classBooking.scheduledClass', 'adjustments.user', 'purchases.location']);

        return view('customer-class-passes.edit', [
            'account' => $account,
            'customerClassPass' => $customerClassPass,
            'locations' => $account->locations()->orderBy('name')->get(),
        ]);
    }

    public function update(
        UpdateCustomerClassPassRequest $request,
        Account $account,
        CustomerClassPass $customerClassPass,
        NormalizeCustomerClassPasses $normalizeCustomerClassPasses,
        ReconcileUnreservedCustomerBookingsForIssuedClassPass $reconcileUnreservedCustomerBookings,
    ): RedirectResponse {
        $this->ensureBelongsToAccount($account, $customerClassPass);

        $validated = $request->validated();

        foreach (['purchased_at', 'opened_at', 'expires_at', 'closed_at'] as $dateField) {
            $validated[$dateField] = DateTimePresenter::parseAccountDateTime($validated[$dateField] ?? null, $account);
        }

        $validated['is_active'] = $request->boolean('is_active');
        $validated['usable_until_at'] = $validated['purchased_at']
            ->timezone(DateTimePresenter::accountTimezone($account))
            ->addDays($customerClassPass->total_validity_days)
            ->timezone((string) config('app.timezone', 'UTC'));

        $requestedStatus = CustomerClassPassStatus::from((string) $validated['status']);

        if ($requestedStatus === CustomerClassPassStatus::Freezed) {
            $validated['is_active'] = true;
        } elseif ($requestedStatus === CustomerClassPassStatus::Active && ! $validated['is_active']) {
            $validated['status'] = CustomerClassPassStatus::Cancelled->value;
        } elseif ($requestedStatus !== CustomerClassPassStatus::Active) {
            $validated['is_active'] = false;
        }

        if (! $validated['is_active'] && blank($validated['closed_at'] ?? null)) {
            $validated['closed_at'] = now();
        }

        if ($validated['is_active']) {
            $validated['closed_at'] = null;
        }

        $customerWithReleasedReservations = null;

        DB::transaction(function () use ($customerClassPass, $normalizeCustomerClassPasses, $validated, &$customerWithReleasedReservations): void {
            $customerClassPass->update($validated);

            if (! $customerClassPass->is_active) {
                $releasedReservations = $customerClassPass->reservations()
                    ->where('status', CustomerClassPassReservationStatus::Reserved->value)
                    ->update([
                        'status' => CustomerClassPassReservationStatus::Released->value,
                        'released_at' => now(),
                        'used_at' => null,
                    ]);

                if ($releasedReservations > 0) {
                    $normalizeCustomerClassPasses->forPass($customerClassPass->refresh());
                    $customerWithReleasedReservations = $customerClassPass->customer()->first();
                }
            }

        });

        if ($customerWithReleasedReservations) {
            $reconcileUnreservedCustomerBookings->executeForCustomer($customerWithReleasedReservations);
        }

        return redirect()->route('dashboard.accounts.customer-class-passes.index', $account)
            ->with('status', __('app.customer_class_pass_updated'));
    }

    public function storePayment(
        StoreCustomerClassPassPaymentRequest $request,
        Account $account,
        CustomerClassPass $customerClassPass,
        RecordManualCustomerClassPassPayment $recordManualCustomerClassPassPayment,
    ): RedirectResponse {
        $this->ensureBelongsToAccount($account, $customerClassPass);
        $this->authorize('manageCustomerClassPasses', $account);

        $location = $account->locations()->whereKey($request->validated('location_id'))->firstOrFail();

        $recordManualCustomerClassPassPayment->execute(
            $account,
            $customerClassPass,
            $location,
            $request->amountCents(),
        );

        return redirect()
            ->route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass])
            ->with('status', __('app.class_pass_payment_recorded'));
    }

    public function storeAdjustment(StoreCustomerClassPassAdjustmentRequest $request, Account $account, CustomerClassPass $customerClassPass, AdjustCustomerClassPassSessions $adjustCustomerClassPassSessions): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $customerClassPass);

        $adjustCustomerClassPassSessions->execute(
            $account,
            $customerClassPass,
            $request->user(),
            $request->signedSessionsDelta(),
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass])
            ->with('status', __('app.customer_class_pass_adjusted'));
    }

    public function storeValidityAdjustment(StoreCustomerClassPassValidityAdjustmentRequest $request, Account $account, CustomerClassPass $customerClassPass, AdjustCustomerClassPassValidityDays $adjustCustomerClassPassValidityDays): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $customerClassPass);

        $adjustCustomerClassPassValidityDays->execute(
            $account,
            $customerClassPass,
            $request->user(),
            $request->signedDaysDelta(),
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass])
            ->with('status', __('app.customer_class_pass_days_adjusted'));
    }

    public function freeze(Request $request, Account $account, CustomerClassPass $customerClassPass, FreezeCustomerClassPass $freezeCustomerClassPass): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $customerClassPass);
        $this->authorize('manageCustomerClassPasses', $account);

        $freezeCustomerClassPass->execute($account, $customerClassPass, $request->user());

        return redirect()
            ->route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass])
            ->with('status', __('app.customer_class_pass_freezed'));
    }

    public function unfreeze(Request $request, Account $account, CustomerClassPass $customerClassPass, UnfreezeCustomerClassPass $unfreezeCustomerClassPass): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $customerClassPass);
        $this->authorize('manageCustomerClassPasses', $account);

        $unfreezeCustomerClassPass->execute($account, $customerClassPass, $request->user());

        return redirect()
            ->route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass])
            ->with('status', __('app.customer_class_pass_unfreezed'));
    }

    private function ensureBelongsToAccount(Account $account, CustomerClassPass $customerClassPass): void
    {
        abort_unless($customerClassPass->account_id === $account->id, 404);
    }

    private function ensureCustomerBelongsToAccount(Account $account, Customer $customer): void
    {
        abort_unless($customer->account_id === $account->id, 404);
    }
}
