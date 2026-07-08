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
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\Pwa\StudioPwaIconGenerator;
use App\Support\ReservedPublicSlugs;
use App\Support\SaasBilling\DeleteAccountWithOwnedUsers;
use App\Support\SlugGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PlatformAccountController extends Controller
{
    public function index(CustomerAuthAvailability $customerAuthAvailability): View
    {
        $accounts = Account::with(['subscription.plan', 'customerAuthSetting'])
            ->withCount(['locations', 'users'])
            ->orderBy('name')
            ->paginate(25);

        return view('platform.accounts.index', [
            'accounts' => $accounts,
            'customerAuthReadiness' => $accounts->getCollection()->mapWithKeys(fn (Account $account): array => [
                $account->getKey() => $customerAuthAvailability->readinessFor($account),
            ]),
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

    public function store(StorePlatformAccountRequest $request, StudioPwaIconGenerator $pwaAssets): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name']);

        $account = DB::transaction(function () use ($request, $validated): Account {
            $account = Account::create(collect($validated)->except([
                'logo',
                'subscription_plan_id',
                'subscription_status',
                'subscription_ends_at',
                'owner_name',
                'owner_email',
                'owner_password',
            ])->all());

            $this->storeLogo($request, $account);
            $account->ensureDefaultTrainerType();

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

        $pwaAssets->ensure($account);

        return redirect()->route('platform.accounts.show', $account)
            ->with('status', __('app.account_created'));
    }

    public function show(Account $account): View
    {
        $account->load(['locations', 'subscription.plan', 'subscriptionPayments.plan'])
            ->loadCount(['users', 'scheduledClasses']);

        return view('platform.accounts.show', [
            'account' => $account,
            'subscriptionPayments' => $account->subscriptionPayments()
                ->with('plan')
                ->latest()
                ->limit(15)
                ->get(),
        ]);
    }

    public function edit(Account $account): View
    {
        $account->load('subscription');

        return view('platform.accounts.edit', $this->formData($account));
    }

    public function update(UpdatePlatformAccountRequest $request, Account $account, StudioPwaIconGenerator $pwaAssets): RedirectResponse
    {
        $previousSlug = $account->slug;
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name'], $account);

        $account->update(collect($validated)->except(['logo', 'subscription_plan_id', 'subscription_status', 'subscription_ends_at'])->all());
        $this->storeLogo($request, $account);
        $this->syncSubscription($account, $validated);

        if ($previousSlug !== $account->slug) {
            $pwaAssets->deleteForSlug($previousSlug);
        }

        $pwaAssets->ensure($account);

        return redirect()->route('platform.accounts.show', $account)
            ->with('status', __('app.account_updated'));
    }

    public function destroy(Account $account, DeleteAccountWithOwnedUsers $deleteAccount): RedirectResponse
    {
        $deleteAccount->execute($account);

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
        $plan = isset($validated['subscription_plan_id'])
            ? SubscriptionPlan::find($validated['subscription_plan_id'])
            : null;
        $endsAt = filled($validated['subscription_ends_at'] ?? null)
            ? Carbon::parse($validated['subscription_ends_at'], $account->timezone ?? config('app.timezone'))->endOfDay()
            : null;

        $account->subscription()->updateOrCreate(
            ['account_id' => $account->id],
            [
                'subscription_plan_id' => $validated['subscription_plan_id'] ?? null,
                'status' => $validated['subscription_status'],
                'started_at' => $account->subscription?->started_at ?? now(),
                'ends_at' => $endsAt,
                'next_payment_at' => $endsAt && $plan?->requires_recurring_payment
                    ? $endsAt->copy()->subDays($plan->renewal_lead_days ?? 2)
                    : null,
                'auto_renew_enabled' => false,
            ],
        );
    }

    private function uniqueSlug(string $source, ?Account $ignore = null): string
    {
        return SlugGenerator::unique($source, 'account', fn (string $candidate): bool => Account::where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists(), ReservedPublicSlugs::all());
    }

    private function storeLogo(StorePlatformAccountRequest|UpdatePlatformAccountRequest $request, Account $account): void
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
