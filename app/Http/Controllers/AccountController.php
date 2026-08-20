<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Support\Ai\AiConversationImageCleaner;
use App\Support\EventFestivalStaffAccess;
use App\Support\PublicScheduleViewRegistry;
use App\Support\Pwa\StudioPwaIconGenerator;
use App\Support\ReservedPublicSlugs;
use App\Support\SlugGenerator;
use App\Support\StudioDashboardData;
use App\Support\WorkingLocationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $accounts = $request->user()
            ->accounts()
            ->withCount('locations')
            ->orderBy('name')
            ->get();

        if (! $request->user()->isPlatformAdmin() && $accounts->count() === 1) {
            return redirect()->to($this->accountDestination($accounts->first()));
        }

        return view('accounts.index', [
            'accounts' => $accounts,
            'accountDestinations' => $accounts->mapWithKeys(fn (Account $account): array => [
                $account->id => $this->accountDestination($account),
            ]),
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

    public function store(StoreAccountRequest $request, StudioPwaIconGenerator $pwaAssets): RedirectResponse
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

        $pwaAssets->ensure($account);

        return redirect()->route('dashboard.accounts.show', $account)
            ->with('status', __('app.account_created'));
    }

    public function show(
        Request $request,
        Account $account,
        StudioDashboardData $studioDashboardData,
        WorkingLocationContext $workingLocationContext,
        EventFestivalStaffAccess $staffAccess,
    ): View|RedirectResponse {
        $this->authorize('view', $account);

        if ($staffAccess->isStaff($request->user(), $account)) {
            return redirect()->route('dashboard.accounts.events.index', $account);
        }

        $workingLocation = $workingLocationContext->location($account);

        return view('accounts.show', [
            'account' => $account,
            'dashboardLocation' => $workingLocation,
            'hasMultipleWorkingLocations' => $workingLocationContext->locations($account)->count() > 1,
            ...$studioDashboardData->forAccount($account, $request->user(), $workingLocation?->id),
        ]);
    }

    private function accountDestination(Account $account): string
    {
        if ($account->pivot?->role === AccountRole::EventFestivalStaff) {
            return route('dashboard.accounts.events.index', $account);
        }

        return route('dashboard.accounts.show', $account);
    }

    public function edit(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        if ($request->query('tab') === 'business') {
            return redirect()->route('dashboard.accounts.general-settings.edit', $account);
        }

        return redirect()->route('dashboard.accounts.owner-profile.edit', $account);
    }

    public function editBrand(Request $request, Account $account): View|RedirectResponse
    {
        $this->authorize('update', $account);

        $legacyRoute = match ($request->query('tab')) {
            'qr' => route('dashboard.accounts.qr-links.show', $account),
            'customer_notifications' => route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'customers']),
            'ai' => route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'telegram']),
            'api' => route('dashboard.accounts.connections.index', [$account, 'tab' => 'api']),
            default => null,
        };

        if ($legacyRoute) {
            return redirect($legacyRoute);
        }

        $allowedTabs = ['formats', 'opening_hours', 'rules', 'pass_rules', 'schedule_view'];
        $activeTab = in_array($request->query('tab'), $allowedTabs, true) ? $request->query('tab') : 'business';

        return view('accounts.brand-edit', [
            'account' => $account,
            'activeTab' => $activeTab,
            'publicScheduleViewOptions' => PublicScheduleViewRegistry::options(),
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account, StudioPwaIconGenerator $pwaAssets): RedirectResponse
    {
        $previousSlug = $account->slug;
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name'], $account);

        $account->update(collect($validated)->except(['brand_tab', 'logo', 'enabled_schedule_kinds_present', 'schedule_kind_colors_present', 'opening_hours_present', 'class_pass_cancellation_rules_present', 'public_group_booking_modal_views_present'])->all());
        $this->storeLogo($request, $account);

        if ($previousSlug !== $account->slug) {
            $pwaAssets->deleteForSlug($previousSlug);
        }

        $pwaAssets->ensure($account);

        $routeParameters = match ($request->input('brand_tab')) {
            'formats' => [$account, 'tab' => 'formats'],
            'opening_hours' => [$account, 'tab' => 'opening_hours'],
            'rules' => [$account, 'tab' => 'rules'],
            'pass_rules' => [$account, 'tab' => 'pass_rules'],
            'schedule_view' => [$account, 'tab' => 'schedule_view'],
            default => [$account],
        };

        return redirect()->route('dashboard.accounts.general-settings.edit', $routeParameters)
            ->with('status', __('app.account_updated'));
    }

    public function destroy(
        Account $account,
        AiConversationImageCleaner $imageCleaner,
    ): RedirectResponse {
        $this->authorize('delete', $account);

        $imageCleaner->deleteForAccount($account);
        $account->delete();

        return redirect()->route('dashboard.accounts.index')
            ->with('status', __('app.account_deleted'));
    }

    private function uniqueSlug(string $source, ?Account $ignore = null): string
    {
        return SlugGenerator::unique($source, 'account', fn (string $candidate): bool => Account::where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists(), ReservedPublicSlugs::all());
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
