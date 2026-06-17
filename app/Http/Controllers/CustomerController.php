<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Account $account): View
    {
        $this->authorize('manageClients', $account);

        return view('customers.index', [
            'account' => $account,
            'customers' => $account->customers()
                ->withCount('classBookings')
                ->orderBy('name')
                ->get(),
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

    public function edit(Account $account, Customer $customer): View
    {
        $this->ensureBelongsToAccount($account, $customer);
        $this->authorize('manageClients', $account);

        return view('customers.edit', [
            'account' => $account,
            'customer' => $customer,
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
