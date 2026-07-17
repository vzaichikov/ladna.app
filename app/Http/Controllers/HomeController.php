<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Support\CustomerAuth\CustomerStudioAccess;
use App\Support\DemoStudioFixture;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(
        private readonly AccountSubscriptionAccess $subscriptionAccess,
        private readonly CustomerStudioAccess $customerStudioAccess,
    ) {}

    public function ukrainian(Request $request): View|RedirectResponse
    {
        return $this->show($request, 'uk');
    }

    public function english(Request $request): View|RedirectResponse
    {
        return $this->show($request, 'en');
    }

    public function app(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectForAuthenticatedUser($request)) {
            return $redirect;
        }

        if ($redirect = $this->redirectForAuthenticatedCustomer($request)) {
            return $redirect;
        }

        return redirect()->route('login');
    }

    private function show(Request $request, string $locale): View|RedirectResponse
    {
        App::setLocale($locale);
        Carbon::setLocale($locale);
        $request->session()->put('locale', $locale);

        if ($redirect = $this->redirectForAuthenticatedUser($request)) {
            return $redirect;
        }

        if ($redirect = $this->redirectForAuthenticatedCustomer($request)) {
            return $redirect;
        }

        return view('welcome', $this->landingData());
    }

    private function redirectForAuthenticatedUser(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        if ($user->isPlatformAdmin()) {
            return redirect()->route('platform.index');
        }

        $accounts = $user->accounts()
            ->orderBy('name')
            ->get();

        if ($accounts->count() === 1) {
            return redirect()->route('dashboard.accounts.show', $accounts->first());
        }

        if ($accounts->isNotEmpty()) {
            return redirect()->route('dashboard.index');
        }

        return null;
    }

    private function redirectForAuthenticatedCustomer(Request $request): ?RedirectResponse
    {
        $customer = $request->user('customer');

        if (! $customer instanceof Customer) {
            return null;
        }

        $destination = $this->customerStudioAccess->destinationFor($customer);

        if ($destination) {
            return redirect()->to($destination);
        }

        return null;
    }

    /**
     * @return array{demoAvailable: bool, trustedStudios: Collection<int, Account>}
     */
    private function landingData(): array
    {
        $demoAccount = Account::query()
            ->active()
            ->where('slug', DemoStudioFixture::AccountSlug)
            ->first();

        return [
            'demoAvailable' => $demoAccount?->isReadOnlyDemo() ?? false,
            'trustedStudios' => $this->trustedStudios(),
        ];
    }

    /**
     * @return Collection<int, Account>
     */
    private function trustedStudios(): Collection
    {
        return Account::publiclyDiscoverable()
            ->with('subscription.plan')
            ->whereHas('locations', fn ($query) => $query->active())
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'logo_path', 'status', 'studio_slogan'])
            ->filter(fn (Account $account): bool => $this->subscriptionAccess->canUsePublicFeatures($account))
            ->take(8)
            ->values();
    }
}
