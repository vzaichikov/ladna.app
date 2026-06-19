<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(): View|RedirectResponse
    {
        $accounts = request()->user()
            ->accounts()
            ->withCount('locations')
            ->orderBy('name')
            ->get();

        if (! request()->user()->isPlatformAdmin() && $accounts->count() === 1) {
            return redirect()->route('dashboard.accounts.show', $accounts->first());
        }

        return view('accounts.index', [
            'accounts' => $accounts,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Account::class);

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
        $this->authorize('create', Account::class);

        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name']);

        $account = DB::transaction(function () use ($request, $validated): Account {
            $account = Account::create(collect($validated)->except('logo')->all());
            $this->storeLogo($request, $account);
            $account->ensureDefaultTrainerType();
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
        ])->loadCount(['locations', 'rooms', 'activityDirections', 'classTypes', 'trainers', 'customers', 'scheduleSeries', 'scheduledClasses', 'classBookings']);

        return view('accounts.show', [
            'account' => $account,
        ]);
    }

    public function edit(Request $request, Account $account): View
    {
        $this->authorize('update', $account);
        $activeTab = $request->query('tab') === 'business' ? 'business' : 'account';

        return view('accounts.edit', [
            'account' => $account,
            'activeTab' => $activeTab,
            'profileUser' => $request->user(),
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name'], $account);

        $account->update(collect($validated)->except('logo')->all());
        $this->storeLogo($request, $account);

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

    private function storeLogo(StoreAccountRequest|UpdateAccountRequest $request, Account $account): void
    {
        if (! $request->hasFile('logo')) {
            return;
        }

        if ($account->logo_path && ! str_starts_with($account->logo_path, 'brand/')) {
            Storage::disk('public')->delete($account->logo_path);
        }

        $account->forceFill([
            'logo_path' => $request->file('logo')->store('account-logos/'.$account->id, 'public'),
        ])->save();
    }
}
