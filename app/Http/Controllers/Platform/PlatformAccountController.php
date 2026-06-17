<?php

namespace App\Http\Controllers\Platform;

use App\Enums\AccountStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlatformAccountRequest;
use App\Http\Requests\UpdatePlatformAccountRequest;
use App\Models\Account;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PlatformAccountController extends Controller
{
    public function index(): View
    {
        return view('platform.accounts.index', [
            'accounts' => Account::with(['subscription.plan'])
                ->withCount(['locations', 'users'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('platform.accounts.create', $this->formData(new Account([
            'status' => AccountStatus::Active,
            'default_language' => 'uk',
            'default_currency' => 'UAH',
            'timezone' => 'Europe/Kyiv',
        ])));
    }

    public function store(StorePlatformAccountRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name']);

        $account = DB::transaction(function () use ($validated): Account {
            $account = Account::create(collect($validated)->except([
                'subscription_plan_id',
                'subscription_status',
                'owner_name',
                'owner_email',
                'owner_password',
            ])->all());

            $owner = User::create([
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'password' => $validated['owner_password'],
                'email_verified_at' => now(),
            ]);

            $account->addOwner($owner);
            $this->syncSubscription($account, $validated);

            return $account;
        });

        return redirect()->route('platform.accounts.show', $account)
            ->with('status', __('app.account_created'));
    }

    public function show(Account $account): View
    {
        $account->load(['locations', 'subscription.plan'])
            ->loadCount(['users', 'scheduledClasses']);

        return view('platform.accounts.show', [
            'account' => $account,
        ]);
    }

    public function edit(Account $account): View
    {
        $account->load('subscription');

        return view('platform.accounts.edit', $this->formData($account));
    }

    public function update(UpdatePlatformAccountRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name'], $account);

        $account->update(collect($validated)->except(['subscription_plan_id', 'subscription_status'])->all());
        $this->syncSubscription($account, $validated);

        return redirect()->route('platform.accounts.show', $account)
            ->with('status', __('app.account_updated'));
    }

    public function destroy(Account $account): RedirectResponse
    {
        $account->delete();

        return redirect()->route('platform.accounts.index')
            ->with('status', __('app.account_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Account $account): array
    {
        return [
            'account' => $account,
            'plans' => SubscriptionPlan::active()->orderBy('sort_order')->orderBy('name')->get(),
            'accountStatuses' => AccountStatus::cases(),
            'subscriptionStatuses' => SubscriptionStatus::cases(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncSubscription(Account $account, array $validated): void
    {
        $account->subscription()->updateOrCreate(
            ['account_id' => $account->id],
            [
                'subscription_plan_id' => $validated['subscription_plan_id'] ?? null,
                'status' => $validated['subscription_status'],
                'started_at' => now(),
            ],
        );
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
