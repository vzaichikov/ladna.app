<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(): View
    {
        $accounts = request()->user()
            ->accounts()
            ->withCount('locations')
            ->orderBy('name')
            ->get();

        return view('accounts.index', [
            'accounts' => $accounts,
        ]);
    }

    public function create(): View
    {
        return view('accounts.create', [
            'account' => new Account([
                'default_language' => 'uk',
                'default_currency' => 'UAH',
                'timezone' => 'Europe/Kyiv',
            ]),
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name']);

        $account = DB::transaction(function () use ($request, $validated): Account {
            $account = Account::create($validated);
            $account->addOwner($request->user());

            return $account;
        });

        return redirect()->route('dashboard.accounts.show', $account)
            ->with('status', __('app.account_created'));
    }

    public function show(Account $account): View
    {
        $this->authorize('view', $account);

        $account->load([
            'locations' => fn ($query) => $query->orderBy('name'),
        ])->loadCount(['locations', 'rooms', 'activityDirections', 'classTypes', 'instructors', 'scheduleSeries', 'scheduledClasses']);

        return view('accounts.show', [
            'account' => $account,
        ]);
    }

    public function edit(Account $account): View
    {
        $this->authorize('update', $account);

        return view('accounts.edit', [
            'account' => $account,
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name'], $account);

        $account->update($validated);

        return redirect()->route('dashboard.accounts.show', $account)
            ->with('status', __('app.account_updated'));
    }

    public function destroy(Account $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        $account->delete();

        return redirect()->route('dashboard.accounts.index')
            ->with('status', __('app.account_deleted'));
    }

    private function uniqueSlug(string $source, ?Account $ignore = null): string
    {
        $slug = Str::slug($source) ?: 'account';
        $candidate = $slug;
        $suffix = 2;

        while (Account::where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
