<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Account;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    public function index(Request $request, string $accountSlug): View
    {
        $account = $this->activeAccount($accountSlug);
        $this->setAccountLocale($account);
        $tab = $request->string('tab')->toString() === 'past' ? 'past' : 'upcoming';

        return view('public.events', [
            'account' => $account,
            'events' => $this->eventsFor($account, $tab),
            'tab' => $tab,
        ]);
    }

    public function show(string $accountSlug, string $eventSlug, PaymentGatewayRegistry $gateways): View
    {
        $account = $this->activeAccount($accountSlug);
        $this->setAccountLocale($account);

        $event = $account->events()
            ->where('slug', $eventSlug)
            ->whereIn('status', [EventStatus::Published->value, EventStatus::Cancelled->value])
            ->with(['location', 'rooms', 'media', 'ticketTypes' => fn ($query) => $query->where('is_active', true)])
            ->firstOrFail();

        return view('public.event', [
            'account' => $account,
            'event' => $event,
            'paymentSettings' => $gateways->availableSettingsFor($account),
        ]);
    }

    private function activeAccount(string $accountSlug): Account
    {
        return Account::active()->where('slug', $accountSlug)->firstOrFail();
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }

    private function eventsFor(Account $account, string $tab): LengthAwarePaginator
    {
        $events = $account->events()
            ->published()
            ->select(['id', 'account_id', 'slug', 'title', 'summary', 'starts_at', 'ends_at', 'timezone'])
            ->with(['media' => fn ($query) => $query
                ->select(['id', 'event_id', 'image_path', 'alt_text', 'sort_order', 'is_cover'])
                ->where('is_cover', true)]);

        if ($tab === 'past') {
            $events
                ->where('ends_at', '<', now())
                ->orderByDesc('starts_at')
                ->orderByDesc('id');
        } else {
            $events
                ->upcoming()
                ->orderBy('starts_at')
                ->orderBy('id');
        }

        return $events->paginate(9)->withQueryString();
    }
}
