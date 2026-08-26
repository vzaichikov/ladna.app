<?php

namespace App\Http\Controllers;

use App\Actions\UnwaiveClassBookingPayment;
use App\Actions\WaiveClassBookingPayment;
use App\Http\Requests\StoreClassBookingPaymentWaiverRequest;
use App\Http\Requests\UnwaiveClassBookingPaymentRequest;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassBookingPaymentWaiver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClassBookingPaymentWaiverController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        abort_unless($account->isOwnedBy($request->user()), 403);

        $status = $request->string('status')->toString();
        $status = in_array($status, ['active', 'unwaived'], true) ? $status : 'all';
        $baseQuery = $account->classBookingPaymentWaivers();
        $statusCounts = [
            'all' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->active()->count(),
            'unwaived' => (clone $baseQuery)->unwaived()->count(),
        ];
        $waivers = $baseQuery
            ->when($status === 'active', fn ($query) => $query->active())
            ->when($status === 'unwaived', fn ($query) => $query->unwaived())
            ->with([
                'classBooking.classPassReservation.customerClassPass',
                'classBooking.manualCashPayment',
                'classBooking.scheduledClass.classType',
            ])
            ->orderByDesc('waived_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('reports.waived-class-payments', [
            'account' => $account,
            'status' => $status,
            'statusCounts' => $statusCounts,
            'waivers' => $waivers,
        ]);
    }

    public function store(
        StoreClassBookingPaymentWaiverRequest $request,
        Account $account,
        ClassBooking $classBooking,
        WaiveClassBookingPayment $waiveClassBookingPayment,
    ): RedirectResponse {
        $waiveClassBookingPayment->execute(
            $account,
            $classBooking,
            $request->user(),
            (string) $request->validated('reason'),
        );

        return redirect()->to($request->safeReturnUrl($account)
            ?? route('dashboard.accounts.reports.unpaid-class-payments', $account))
            ->with('status', __('app.class_booking_payment_waived'));
    }

    public function unwaive(
        UnwaiveClassBookingPaymentRequest $request,
        Account $account,
        ClassBookingPaymentWaiver $classBookingPaymentWaiver,
        UnwaiveClassBookingPayment $unwaiveClassBookingPayment,
    ): RedirectResponse {
        $unwaiveClassBookingPayment->execute(
            $account,
            $classBookingPaymentWaiver,
            $request->user(),
            (string) $request->validated('reason'),
        );

        return redirect()->to($request->safeReturnUrl($account)
            ?? route('dashboard.accounts.reports.unpaid-class-payments.waived', $account))
            ->with('status', __('app.class_booking_payment_unwaived'));
    }
}
