<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Support\SlugGenerator;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function edit(Request $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        if ($request->query('tab') === 'business') {
            return redirect()->route('dashboard.accounts.brand.edit', $account);
        }

        return redirect()->route('dashboard.accounts.owner-profile.edit', $account);
    }

    public function editBrand(Request $request, Account $account): View
    {
        $this->authorize('update', $account);
        $customerLoginUrl = route('customer.studio.login', $account->slug);
        $activeTab = in_array($request->query('tab'), ['formats', 'opening_hours', 'qr', 'api'], true) ? $request->query('tab') : 'business';

        return view('accounts.brand-edit', [
            'account' => $account,
            'activeTab' => $activeTab,
            'customerLoginUrl' => $customerLoginUrl,
            'customerLoginQrSvg' => $this->qrCodeSvg($customerLoginUrl),
            'apiTokens' => $activeTab === 'api'
                ? $account->apiTokens()->latest()->get()
                : collect(),
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $validated = $request->validated();
        $validated['slug'] = $this->uniqueSlug(($validated['slug'] ?? null) ?: $validated['name'], $account);

        $account->update(collect($validated)->except(['brand_tab', 'logo', 'enabled_schedule_kinds_present', 'schedule_kind_colors_present', 'opening_hours_present'])->all());
        $this->storeLogo($request, $account);

        $routeParameters = match ($request->input('brand_tab')) {
            'formats' => [$account, 'tab' => 'formats'],
            'opening_hours' => [$account, 'tab' => 'opening_hours'],
            default => [$account],
        };

        return redirect()->route('dashboard.accounts.brand.edit', $routeParameters)
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
        return SlugGenerator::unique($source, 'account', fn (string $candidate): bool => Account::where('slug', $candidate)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists());
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

    private function qrCodeSvg(string $url): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(320),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($url);
    }
}
