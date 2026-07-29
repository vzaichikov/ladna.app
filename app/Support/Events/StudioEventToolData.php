<?php

namespace App\Support\Events;

use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Enums\EventVenueKind;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use App\Support\DateTimePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudioEventToolData
{
    private const MaximumPeriodDays = 366;

    private const DefaultLimit = 20;

    private const MaximumLimit = 50;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function overview(Account $account, array $arguments): array
    {
        [$fromDate, $toDate, $startsAt, $endsAt] = $this->period($account, $arguments);
        $statusBucket = filled($arguments['status_bucket'] ?? null)
            ? (string) $arguments['status_bucket']
            : 'upcoming';
        $query = Str::of((string) ($arguments['query'] ?? ''))->squish()->toString();
        $locationId = filled($arguments['location_id'] ?? null) ? (int) $arguments['location_id'] : null;
        $limit = min(max((int) ($arguments['limit'] ?? self::DefaultLimit), 1), self::MaximumLimit);
        $this->ensureLocationBelongsToAccount($account, $locationId);

        $events = $this->eventQuery($account)
            ->whereBetween('starts_at', [$startsAt, $endsAt])
            ->when($locationId, fn (Builder $query, int $id): Builder => $query->where('location_id', $id))
            ->when($query !== '', function (Builder $eventQuery) use ($query): void {
                $escaped = addcslashes($query, '\\%_');
                $eventQuery->where(fn (Builder $eventQuery): Builder => $eventQuery
                    ->where('title', 'like', '%'.$escaped.'%')
                    ->orWhere('slug', 'like', '%'.$escaped.'%'));
            })
            ->when($statusBucket === 'upcoming', fn (Builder $query): Builder => $query
                ->where('status', EventStatus::Published->value)
                ->where('ends_at', '>=', now()))
            ->when($statusBucket === 'draft', fn (Builder $query): Builder => $query->where('status', EventStatus::Draft->value))
            ->when($statusBucket === 'past', fn (Builder $query): Builder => $query
                ->where('status', EventStatus::Published->value)
                ->where('ends_at', '<', now()))
            ->when($statusBucket === 'cancelled', fn (Builder $query): Builder => $query
                ->whereIn('status', [EventStatus::Cancelled->value, EventStatus::Archived->value]))
            ->orderBy('starts_at', $statusBucket === 'past' ? 'desc' : 'asc')
            ->orderBy('id', $statusBucket === 'past' ? 'desc' : 'asc')
            ->limit($limit + 1)
            ->get();

        $truncated = $events->count() > $limit;
        $events = $events->take($limit)->values();
        $orderStatusCounts = $this->orderStatusCounts($account, $events->pluck('id'));

        return [
            'status' => $events->isEmpty() ? 'not_found' : 'found',
            'timezone' => DateTimePresenter::accountTimezone($account),
            'period' => [
                'date_from' => $fromDate,
                'date_to' => $toDate,
            ],
            'filters' => [
                'status_bucket' => $statusBucket,
                'location_id' => $locationId,
                'query_applied' => $query !== '',
            ],
            'returned' => $events->count(),
            'truncated' => $truncated,
            'events' => $events
                ->map(fn (Event $event): array => $this->eventPayload(
                    $account,
                    $event,
                    $orderStatusCounts->get($event->id, []),
                    includeTicketTypes: false,
                ))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Account $account, int $eventId): array
    {
        $event = $this->eventQuery($account)->whereKey($eventId)->first();

        if (! $event) {
            return [
                'status' => 'not_found',
                'event_id' => $eventId,
            ];
        }

        $orderStatusCounts = $this->orderStatusCounts($account, collect([$event->id]));

        return [
            'status' => 'found',
            'event' => $this->eventPayload(
                $account,
                $event,
                $orderStatusCounts->get($event->id, []),
                includeTicketTypes: true,
            ),
        ];
    }

    private function eventQuery(Account $account): Builder
    {
        $inventoryStatuses = [
            EventOrderStatus::Pending->value,
            EventOrderStatus::Paid->value,
            EventOrderStatus::RefundRequired->value,
        ];
        $revenueStatuses = [
            EventOrderStatus::Paid->value,
            EventOrderStatus::RefundRequired->value,
            EventOrderStatus::PaidRequiresRefund->value,
        ];
        $refundStatuses = [
            EventOrderStatus::RefundRequired->value,
            EventOrderStatus::PaidRequiresRefund->value,
        ];

        return Event::query()
            ->whereBelongsTo($account)
            ->with([
                'location:id,account_id,name,address',
                'rooms:id,name',
                'ticketTypes' => fn ($query) => $query
                    ->select([
                        'id',
                        'account_id',
                        'event_id',
                        'name',
                        'inventory',
                        'price_cents',
                        'early_bird_price_cents',
                        'is_active',
                        'sort_order',
                    ])
                    ->withSum([
                        'orderItems as sold_or_held_quantity' => fn (Builder $query): Builder => $query
                            ->whereHas('order', fn (Builder $query): Builder => $query
                                ->whereIn('status', $inventoryStatuses)
                                ->where(fn (Builder $query): Builder => $query
                                    ->where('status', '!=', EventOrderStatus::Pending->value)
                                    ->orWhere('expires_at', '>', now()))),
                    ], 'quantity'),
            ])
            ->withCount([
                'tickets',
                'tickets as valid_tickets_count' => fn (Builder $query): Builder => $query->where('status', EventTicketStatus::Valid->value),
                'tickets as voided_tickets_count' => fn (Builder $query): Builder => $query->where('status', EventTicketStatus::Voided->value),
                'tickets as refunded_tickets_count' => fn (Builder $query): Builder => $query->where('status', EventTicketStatus::Refunded->value),
                'tickets as checked_in_tickets_count' => fn (Builder $query): Builder => $query->where('is_checked_in', true),
                'orders as refund_required_orders_count' => fn (Builder $query): Builder => $query->whereIn('status', $refundStatuses),
            ])
            ->withSum([
                'orders as gross_revenue_cents' => fn (Builder $query): Builder => $query->whereIn('status', $revenueStatuses),
                'orders as refund_required_cents' => fn (Builder $query): Builder => $query->whereIn('status', $refundStatuses),
            ], 'amount_cents')
            ->withSum([
                'orderItems as sold_or_held_quantity' => fn (Builder $query): Builder => $query
                    ->whereHas('order', fn (Builder $query): Builder => $query
                        ->whereIn('status', $inventoryStatuses)
                        ->where(fn (Builder $query): Builder => $query
                            ->where('status', '!=', EventOrderStatus::Pending->value)
                            ->orWhere('expires_at', '>', now()))),
            ], 'quantity');
    }

    /**
     * @param  array<string, int>  $orderStatusCounts
     * @return array<string, mixed>
     */
    private function eventPayload(
        Account $account,
        Event $event,
        array $orderStatusCounts,
        bool $includeTicketTypes,
    ): array {
        $timezone = DateTimePresenter::safeTimezone($event->timezone ?: $account->timezone);
        $soldOrHeld = (int) ($event->sold_or_held_quantity ?? 0);
        $ticketInventory = (int) $event->ticketTypes
            ->where('is_active', true)
            ->sum('inventory');
        $remainingTicketInventory = max(0, $ticketInventory - $soldOrHeld);
        $remainingCapacity = $event->capacity === null
            ? null
            : max(0, (int) $event->capacity - $soldOrHeld);
        $remainingAdmission = $remainingCapacity === null
            ? $remainingTicketInventory
            : min($remainingTicketInventory, $remainingCapacity);

        $payload = [
            'event_id' => $event->id,
            'slug' => $event->slug,
            'title' => $event->title,
            'status' => $event->status->value,
            'starts_at' => $event->starts_at?->copy()->timezone($timezone)->toIso8601String(),
            'ends_at' => $event->ends_at?->copy()->timezone($timezone)->toIso8601String(),
            'timezone' => $timezone,
            'venue' => $this->venue($event),
            'inventory' => [
                'capacity' => $event->capacity,
                'sold_or_held' => $soldOrHeld,
                'remaining_capacity' => $remainingCapacity,
                'remaining_ticket_inventory' => $remainingTicketInventory,
                'remaining_admission_inventory' => $remainingAdmission,
            ],
            'tickets' => [
                'issued' => (int) $event->tickets_count,
                'valid' => (int) $event->valid_tickets_count,
                'voided' => (int) $event->voided_tickets_count,
                'refunded' => (int) $event->refunded_tickets_count,
                'checked_in' => (int) $event->checked_in_tickets_count,
            ],
            'orders_by_status' => $orderStatusCounts,
            'revenue' => $this->money((int) ($event->gross_revenue_cents ?? 0), (string) $event->currency),
            'refund_required' => [
                'orders' => (int) $event->refund_required_orders_count,
                'amount' => $this->money((int) ($event->refund_required_cents ?? 0), (string) $event->currency),
            ],
        ];

        if ($includeTicketTypes) {
            $payload['ticket_types'] = $event->ticketTypes
                ->sortBy('sort_order')
                ->map(fn ($ticketType): array => [
                    'ticket_type_id' => $ticketType->id,
                    'name' => $ticketType->name,
                    'is_active' => (bool) $ticketType->is_active,
                    'inventory' => (int) $ticketType->inventory,
                    'sold_or_held' => (int) ($ticketType->sold_or_held_quantity ?? 0),
                    'remaining' => max(0, (int) $ticketType->inventory - (int) ($ticketType->sold_or_held_quantity ?? 0)),
                    'regular_price' => $this->money((int) $ticketType->price_cents, (string) $event->currency),
                    'early_bird_price' => $ticketType->early_bird_price_cents === null
                        ? null
                        : $this->money((int) $ticketType->early_bird_price_cents, (string) $event->currency),
                ])
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function venue(Event $event): array
    {
        if ($event->venue_kind === EventVenueKind::External) {
            return [
                'kind' => EventVenueKind::External->value,
                'name' => $event->external_venue_name,
                'address' => $event->external_address,
            ];
        }

        return [
            'kind' => EventVenueKind::Studio->value,
            'location_id' => $event->location?->id,
            'name' => $event->location?->name,
            'address' => $event->location?->address,
            'rooms' => $event->rooms->pluck('name')->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, int>  $eventIds
     * @return Collection<int, array<string, int>>
     */
    private function orderStatusCounts(Account $account, Collection $eventIds): Collection
    {
        if ($eventIds->isEmpty()) {
            return collect();
        }

        return EventOrder::query()
            ->whereBelongsTo($account)
            ->whereIn('event_id', $eventIds)
            ->selectRaw('event_id, status, COUNT(*) as aggregate')
            ->groupBy('event_id', 'status')
            ->get()
            ->groupBy('event_id')
            ->map(fn (Collection $rows): array => $rows
                ->mapWithKeys(fn (EventOrder $row): array => [
                    $row->status->value => (int) $row->aggregate,
                ])
                ->sortKeys()
                ->all());
    }

    /**
     * @return array{0: string, 1: string, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function period(Account $account, array $arguments): array
    {
        $timezone = DateTimePresenter::accountTimezone($account);
        $today = CarbonImmutable::now($timezone);
        $fromDate = filled($arguments['date_from'] ?? null)
            ? (string) $arguments['date_from']
            : $today->toDateString();
        $toDate = filled($arguments['date_to'] ?? null)
            ? (string) $arguments['date_to']
            : $today->addDays(365)->toDateString();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $timezone);

        if ($from->greaterThan($to) || $from->diffInDays($to) > self::MaximumPeriodDays) {
            throw ValidationException::withMessages([
                'date_to' => 'The event period must end on or after date_from and may not exceed 366 days.',
            ]);
        }

        return [
            $fromDate,
            $toDate,
            $from->startOfDay()->timezone((string) config('app.timezone')),
            $to->endOfDay()->timezone((string) config('app.timezone')),
        ];
    }

    private function ensureLocationBelongsToAccount(Account $account, ?int $locationId): void
    {
        if ($locationId !== null && ! $account->locations()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages([
                'location_id' => 'The selected location does not belong to this studio.',
            ]);
        }
    }

    /**
     * @return array{currency: string, amount_cents: int, amount: string}
     */
    private function money(int $amountCents, string $currency): array
    {
        return [
            'currency' => $currency,
            'amount_cents' => $amountCents,
            'amount' => number_format($amountCents / 100, 2, '.', ''),
        ];
    }
}
