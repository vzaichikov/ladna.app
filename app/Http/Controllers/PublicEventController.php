<?php

namespace App\Http\Controllers;

use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Models\Account;
use App\Models\EventOrderItem;
use App\Models\EventTicketType;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function show(
        string $accountSlug,
        string $eventSlug,
        PaymentGatewayRegistry $gateways,
        CustomerAuthAvailability $authAvailability,
    ): View {
        $account = $this->activeAccount($accountSlug);
        $this->setAccountLocale($account);

        $event = $account->events()
            ->where('slug', $eventSlug)
            ->whereIn('status', [EventStatus::Published->value, EventStatus::Cancelled->value])
            ->with([
                'location',
                'rooms',
                'media',
                'ticketTypes' => fn (HasMany $query): HasMany => $query
                    ->where('is_active', true)
                    ->withSoldOrHeldQuantity()
                    ->withSum([
                        'orderItems as early_bird_sold_or_held_quantity' => fn (Builder|HasMany $query): Builder|HasMany => $this
                            ->reservedOrderItems($query)
                            ->where('price_tier', 'early_bird'),
                    ], 'quantity'),
            ])
            ->firstOrFail();

        $eventRemainingCapacity = $event->capacity === null
            ? null
            : max(0, $event->capacity - (int) $this->reservedOrderItems(
                EventOrderItem::query()->where('event_id', $event->id)
            )->sum('quantity'));
        $checkoutTicketTypes = $event->ticketTypes->map(function (EventTicketType $ticketType) use ($event, $eventRemainingCapacity): array {
            $remainingQuantity = max(0, $ticketType->inventory - (int) $ticketType->getAttribute('sold_or_held_quantity'));
            $remainingQuantity = min($remainingQuantity, $eventRemainingCapacity ?? PHP_INT_MAX);
            $salesOpen = $ticketType->salesAreOpen();
            $maxQuantity = $salesOpen ? min($ticketType->max_per_order, $remainingQuantity) : 0;
            $earlyBirdPeriodIsOpen = $ticketType->early_bird_price_cents !== null
                && $ticketType->early_bird_ends_at?->isFuture();
            $earlyBirdRemainingQuantity = $earlyBirdPeriodIsOpen
                ? min(
                    $remainingQuantity,
                    $ticketType->early_bird_quota === null
                        ? $remainingQuantity
                        : max(0, $ticketType->early_bird_quota - (int) $ticketType->getAttribute('early_bird_sold_or_held_quantity')),
                )
                : 0;
            $earlyBirdMaxQuantity = min($maxQuantity, $earlyBirdRemainingQuantity);
            $earlyBirdAvailable = $earlyBirdMaxQuantity > 0;

            return [
                'id' => $ticketType->id,
                'name' => $ticketType->name,
                'description' => $ticketType->description,
                'remaining_quantity' => $remainingQuantity,
                'max_quantity' => $maxQuantity,
                'sales_open' => $salesOpen,
                'early_bird_available' => $earlyBirdAvailable,
                'early_bird_max_quantity' => $earlyBirdMaxQuantity,
                'early_bird_price_cents' => $earlyBirdAvailable ? $ticketType->early_bird_price_cents : null,
                'early_bird_quota' => $ticketType->early_bird_quota,
                'early_bird_remaining_quantity' => $earlyBirdRemainingQuantity,
                'early_bird_ends_at_label' => $ticketType->early_bird_ends_at
                    ?->copy()
                    ->timezone($event->timezone)
                    ->format('d.m.Y H:i'),
                'regular_price_cents' => $ticketType->price_cents,
                'price_cents' => $earlyBirdAvailable ? $ticketType->early_bird_price_cents : $ticketType->price_cents,
            ];
        });
        $hasPurchasableTickets = $event->status === EventStatus::Published
            && $event->starts_at->isFuture()
            && $checkoutTicketTypes->contains(fn (array $ticketType): bool => $ticketType['max_quantity'] > 0);

        return view('public.event', [
            'account' => $account,
            'event' => $event,
            'paymentSettings' => $gateways->availableSettingsFor($account),
            'checkoutTicketTypes' => $checkoutTicketTypes,
            'eventRemainingCapacity' => $eventRemainingCapacity,
            'hasPurchasableTickets' => $hasPurchasableTickets,
            'googleEmailPrefillAvailable' => $authAvailability->googleSetting() !== null,
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

    private function reservedOrderItems(Builder|HasMany $query): Builder|HasMany
    {
        return $query->whereHas('order', fn (Builder $query): Builder => $query
            ->whereIn('status', [
                EventOrderStatus::Pending->value,
                EventOrderStatus::Paid->value,
                EventOrderStatus::RefundRequired->value,
            ])
            ->where(fn (Builder $query): Builder => $query
                ->where('status', '!=', EventOrderStatus::Pending->value)
                ->orWhere('expires_at', '>', now())));
    }
}
