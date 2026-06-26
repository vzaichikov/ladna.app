<?php

namespace App\Http\Controllers;

use App\Actions\AdjustCustomerClassPassSessions;
use App\Actions\IssueCustomerClassPass;
use App\Http\Requests\StoreCustomerClassPassAdjustmentRequest;
use App\Http\Requests\StoreCustomerClassPassRequest;
use App\Http\Requests\UpdateCustomerClassPassRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class CustomerClassPassController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->ensureCurrentUserOwns($account);

        $term = trim((string) $request->query('q', ''));
        $state = (string) $request->query('state', 'active');
        $enabledScheduleKinds = $account->enabledScheduleKindValues();
        $requestedScheduleKind = (string) $request->query('schedule_kind', '');
        $scheduleKind = in_array($requestedScheduleKind, $enabledScheduleKinds, true) ? $requestedScheduleKind : '';

        $customerClassPasses = $account->customerClassPasses()
            ->with(['customer', 'classPassPlan.classTypes', 'classPassPlan.trainerTypes', 'classPassPlan.rooms'])
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
            ->when($state === 'active', fn ($query) => $query->where('is_active', true))
            ->when($state === 'inactive', fn ($query) => $query->where('is_active', false))
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
            'enabledScheduleKinds' => $enabledScheduleKinds,
        ]);
    }

    public function store(StoreCustomerClassPassRequest $request, Account $account, Customer $customer, IssueCustomerClassPass $issueCustomerClassPass): RedirectResponse
    {
        $this->ensureCustomerBelongsToAccount($account, $customer);
        $classPassPlan = $account->classPassPlans()->whereKey($request->validated('class_pass_plan_id'))->firstOrFail();

        $issueCustomerClassPass->execute($account, $customer, $classPassPlan);

        return redirect()->route('dashboard.accounts.customers.edit', [$account, $customer])
            ->with('status', __('app.customer_class_pass_issued'));
    }

    public function edit(Account $account, CustomerClassPass $customerClassPass): View
    {
        $this->ensureBelongsToAccount($account, $customerClassPass);
        $this->ensureCurrentUserOwns($account);

        $customerClassPass->load(['customer', 'classPassPlan.classTypes', 'reservations.classBooking.scheduledClass', 'adjustments.user']);

        return view('customer-class-passes.edit', [
            'account' => $account,
            'customerClassPass' => $customerClassPass,
        ]);
    }

    public function update(UpdateCustomerClassPassRequest $request, Account $account, CustomerClassPass $customerClassPass): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $customerClassPass);

        $validated = $request->validated();
        $validated['is_active'] = $request->boolean('is_active');
        $validated['usable_until_at'] = Carbon::parse($validated['purchased_at'])
            ->addDays($customerClassPass->total_validity_days);

        if (! $validated['is_active'] && blank($validated['closed_at'] ?? null)) {
            $validated['closed_at'] = now();
        }

        if ($validated['is_active']) {
            $validated['closed_at'] = null;
        }

        $customerClassPass->update($validated);

        return redirect()->route('dashboard.accounts.customer-class-passes.index', $account)
            ->with('status', __('app.customer_class_pass_updated'));
    }

    public function storeAdjustment(StoreCustomerClassPassAdjustmentRequest $request, Account $account, CustomerClassPass $customerClassPass, AdjustCustomerClassPassSessions $adjustCustomerClassPassSessions): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $customerClassPass);
        $this->ensureCurrentUserOwns($account);

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

    private function ensureBelongsToAccount(Account $account, CustomerClassPass $customerClassPass): void
    {
        abort_unless($customerClassPass->account_id === $account->id, 404);
    }

    private function ensureCustomerBelongsToAccount(Account $account, Customer $customer): void
    {
        abort_unless($customer->account_id === $account->id, 404);
    }

    private function ensureCurrentUserOwns(Account $account): void
    {
        abort_unless($account->isOwnedBy(request()->user()), 403);
    }
}
