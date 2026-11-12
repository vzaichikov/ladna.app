<?php

namespace App\View\Components\CustomerAuth;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Location;
use App\Support\Telegram\CustomerTelegramLinkResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class Navigation extends Component
{
    public bool $showPortalLinks;

    /** @var Collection<int, Location> */
    public Collection $publicLocations;

    public ?string $customerTelegramBotLink;

    public function __construct(
        CustomerTelegramLinkResolver $telegramLinks,
        public Account $account,
        public ?Customer $customer,
        public bool $hasDashboardLink = false,
    ) {
        $this->showPortalLinks = $customer?->profileIsComplete() ?? false;
        $this->publicLocations = $customer ? $account->locations()
            ->active()
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'slug']) : collect();
        $this->customerTelegramBotLink = $customer
            ? $telegramLinks->linkForPlacement($account, CustomerTelegramLinkResolver::PlacementCustomerDashboard)
            : null;
    }

    public function render(): View
    {
        return view('components.customer-auth.navigation');
    }
}
