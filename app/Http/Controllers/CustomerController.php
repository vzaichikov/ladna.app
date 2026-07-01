<?php

namespace App\Http\Controllers;

use App\Actions\ReconcileUnreservedCustomerBookingsForIssuedClassPass;
use App\Enums\CustomerClassPassStatus;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->authorize('manageClients', $account);

        $term = trim((string) $request->query('q', ''));

        return view('customers.index', [
            'account' => $account,
            'customers' => $account->customers()
                ->withCount([
                    'classBookings',
                    'customerClassPasses as active_class_passes_count' => fn ($query) => $query->active(),
                ])
                ->when($term !== '', function ($query) use ($term): void {
                    $query->where(function ($query) use ($term): void {
                        $query->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%")
                            ->orWhereHas('customerClassPasses', fn ($query) => $query->where('code', 'like', "%{$term}%"));
                    });
                })
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'searchTerm' => $term,
        ]);
    }

    public function create(Account $account): View
    {
        $this->authorize('manageClients', $account);

        return view('customers.create', [
            'account' => $account,
            'customer' => new Customer(['default_language' => $account->default_language]),
        ]);
    }

    public function store(StoreCustomerRequest $request, Account $account): RedirectResponse
    {
        $validated = $this->customerAttributes($request->validated());

        $account->customers()->create($validated);

        return redirect()->route('dashboard.accounts.customers.index', $account)
            ->with('status', __('app.customer_created'));
    }

    public function show(Account $account, Customer $customer): never
    {
        abort(404);
    }

    public function edit(Request $request, Account $account, Customer $customer, ReconcileUnreservedCustomerBookingsForIssuedClassPass $reconcileUnreservedCustomerBookings): View
    {
        $this->ensureBelongsToAccount($account, $customer);
        $this->authorize('manageClients', $account);
        $classPassBackfillPreview = null;

        if ($request->boolean('class_pass_backfill_preview') && ($request->user()?->can('manageCustomerClassPasses', $account) ?? false)) {
            $classPassBackfillPreview = $reconcileUnreservedCustomerBookings->previewForCustomer($customer);
        }

        $customerClassPasses = $customer->customerClassPasses()
            ->with([
                'classPassPlan.classTypes',
                'classPassPlan.trainerTypes',
                'classPassPlan.rooms',
                'issuedLocation',
                'reservations.classBooking.scheduledClass.classType',
            ])
            ->where('is_active', true)
            ->whereIn('status', [
                CustomerClassPassStatus::Active->value,
                CustomerClassPassStatus::Freezed->value,
            ])
            ->orderByRaw('CASE WHEN opened_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('opened_at')
            ->orderByDesc('purchased_at')
            ->paginate(5, ['*'], 'class_passes_page')
            ->withQueryString();

        return view('customers.edit', [
            'account' => $account,
            'customer' => $customer->load([
                'classBookings.scheduledClass.account',
                'classBookings.scheduledClass.classType',
                'classBookings.scheduledClass.location',
                'classBookings.classPassReservation.customerClassPass',
            ]),
            'customerClassPasses' => $customerClassPasses,
            'classPassPlans' => $account->classPassPlans()
                ->active()
                ->with(['classTypes', 'trainerTypes', 'rooms'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'locations' => $account->locations()->active()->orderBy('name')->get(),
            'classPassBackfillPreview' => $classPassBackfillPreview,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Account $account, Customer $customer): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $customer);

        $customer->update($this->customerAttributes($request->validated()));

        return redirect()->route('dashboard.accounts.customers.index', $account)
            ->with('status', __('app.customer_updated'));
    }

    public function destroy(Account $account, Customer $customer): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $customer);
        $this->authorize('manageClients', $account);

        $customer->delete();

        return redirect()->route('dashboard.accounts.customers.index', $account)
            ->with('status', __('app.customer_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, Customer $customer): void
    {
        abort_unless($customer->account_id === $account->id, 404);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function customerAttributes(array $validated): array
    {
        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        return $validated;
    }
}
