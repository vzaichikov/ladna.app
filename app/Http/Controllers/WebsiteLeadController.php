<?php

namespace App\Http\Controllers;

use App\Enums\WebsiteLeadStatus;
use App\Http\Requests\StoreWebsiteLeadRequest;
use App\Http\Requests\UpdateWebsiteLeadStatusRequest;
use App\Models\Account;
use App\Models\WebsiteLead;
use App\Support\QuickBookingOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebsiteLeadController extends Controller
{
    public function index(Request $request, Account $account, QuickBookingOptions $quickBookingOptions): View
    {
        $this->authorize('manageWebsiteLeads', $account);

        $status = WebsiteLeadStatus::tryFrom((string) $request->query('status'));
        $term = trim((string) $request->query('q', ''));
        $quickBookingData = $quickBookingOptions->forAccount($account);

        return view('website-leads.index', [
            'account' => $account,
            'websiteLeads' => $account->websiteLeads()
                ->with(['customer', 'classBooking.scheduledClass'])
                ->when($status, fn ($query) => $query->where('status', $status->value))
                ->when($term !== '', function ($query) use ($term): void {
                    $query->where(function ($query) use ($term): void {
                        $query->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
                })
                ->latest()
                ->paginate(20)
                ->withQueryString(),
            'statuses' => WebsiteLeadStatus::cases(),
            'activeStatus' => $status?->value ?? '',
            'searchTerm' => $term,
            'websiteLeadTimezone' => $account->timezone ?? config('app.timezone'),
            'quickBookingOptions' => $quickBookingData['options'],
            'quickBookingLocations' => $quickBookingData['locations'],
            'quickBookingRooms' => $quickBookingData['rooms'],
            'quickBookingTrainers' => $quickBookingData['trainers'],
            'customerSearchUrl' => route('dashboard.accounts.customers.search', $account),
            'groupAvailabilityUrl' => route('dashboard.accounts.quick-bookings.group-availability', $account),
        ]);
    }

    public function store(StoreWebsiteLeadRequest $request, Account $account): RedirectResponse
    {
        $account->websiteLeads()->create($request->validated());

        return redirect()->route('dashboard.accounts.website-leads.index', $account)
            ->with('status', __('app.website_lead_created'));
    }

    public function update(UpdateWebsiteLeadStatusRequest $request, Account $account, WebsiteLead $websiteLead): RedirectResponse
    {
        $this->ensureBelongsToAccount($account, $websiteLead);
        $websiteLead->update($request->validated());

        return back()->with('status', __('app.website_lead_updated'));
    }

    public function destroy(Account $account, WebsiteLead $websiteLead): RedirectResponse
    {
        $this->authorize('manageWebsiteLeads', $account);
        $this->ensureBelongsToAccount($account, $websiteLead);
        $websiteLead->delete();

        return back()->with('status', __('app.website_lead_deleted'));
    }

    private function ensureBelongsToAccount(Account $account, WebsiteLead $websiteLead): void
    {
        abort_unless($websiteLead->account_id === $account->id, 404);
    }
}
