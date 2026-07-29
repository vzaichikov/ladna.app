<?php

namespace App\Actions;

use App\Enums\AccountRole;
use App\Enums\AccountStatus;
use App\Enums\EventOrderStatus;
use App\Enums\EventStatus;
use App\Enums\EventTicketStatus;
use App\Enums\EventVenueKind;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\AccountAiProviderCredential;
use App\Models\ClassType;
use App\Models\CustomerPurchase;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventOrderItem;
use App\Models\EventTicket;
use App\Models\EventTicketType;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\TelegramBotInstallation;
use App\Models\User;
use App\Support\DemoStudioFixture;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncDemoStudioShowcase
{
    /**
     * @return array{
     *     account_id: int,
     *     account_slug: string,
     *     owner_email: string,
     *     resources: array<string, array{create: int, update: int, noop: int}>,
     *     event_slugs: array<int, string>,
     *     order_ids: array<int, string>,
     *     ticket_codes: array<int, string>
     * }
     */
    public function preview(int $expectedAccountId): array
    {
        [$account, $owner] = $this->validatedTarget($expectedAccountId);

        return $this->buildPlan($account, $owner);
    }

    /**
     * @return array{
     *     account: Account,
     *     plan: array{
     *         account_id: int,
     *         account_slug: string,
     *         owner_email: string,
     *         resources: array<string, array{create: int, update: int, noop: int}>,
     *         event_slugs: array<int, string>,
     *         order_ids: array<int, string>,
     *         ticket_codes: array<int, string>
     *     }
     * }
     */
    public function execute(int $expectedAccountId): array
    {
        return DB::transaction(function () use ($expectedAccountId): array {
            [$account, $owner] = $this->validatedTarget($expectedAccountId, true);
            $plan = $this->buildPlan($account, $owner);

            $this->synchronizeValidatedTarget($account, $owner);

            return [
                'account' => $account->fresh(),
                'plan' => $plan,
            ];
        }, 3);
    }

    public function synchronizeValidatedTarget(Account $account, User $owner): void
    {
        $this->synchronizeAccountSettings($account);
        $classTypes = $this->synchronizeInternalClassTypes($account);
        $this->synchronizeInternalClasses($account, $classTypes);
        $this->synchronizeEvents($account, $owner);
    }

    /**
     * @return array{0: Account, 1: User}
     */
    private function validatedTarget(int $expectedAccountId, bool $lock = false): array
    {
        if ($expectedAccountId < 1) {
            throw new RuntimeException('A positive --expected-account-id is required.');
        }

        $accountQuery = Account::query()->where('slug', DemoStudioFixture::AccountSlug);

        if ($lock) {
            $accountQuery->lockForUpdate();
        }

        $account = $accountQuery->first();

        if (! $account) {
            throw new RuntimeException('The configured demo studio does not exist.');
        }

        if ($account->id !== $expectedAccountId) {
            throw new RuntimeException("Expected account #{$expectedAccountId}, but the demo slug belongs to account #{$account->id}.");
        }

        if (! $account->isReadOnlyDemo()) {
            throw new RuntimeException('Refusing to modify an account that is not in demo_readonly mode.');
        }

        if ($account->status !== AccountStatus::Active) {
            throw new RuntimeException('Refusing to modify an inactive demo account.');
        }

        $ownerEmail = $this->configuredOwnerEmail();
        $memberships = $account->memberships()
            ->with('user')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
        $membership = $memberships->first();
        $owner = $membership?->user;

        if ($memberships->count() !== 1
            || $membership?->role !== AccountRole::Owner
            || ! $owner
            || $owner->email !== $ownerEmail
            || $owner->accountMemberships()->count() !== 1) {
            throw new RuntimeException('The demo studio has unexpected owner memberships.');
        }

        if ($lock) {
            User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
        }

        $this->assertNoExternalOrLiveProviderData($account);

        return [$account, $owner];
    }

    private function configuredOwnerEmail(): string
    {
        $email = config('demo-studio.owner.email');

        if (! is_string($email) || blank($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Demo studio owner email is not configured.');
        }

        return $email;
    }

    private function assertNoExternalOrLiveProviderData(Account $account): void
    {
        $hasSubscriptionData = $account->subscription()->exists()
            || $account->subscriptionPayments()->exists()
            || $account->subscriptionPaymentMethod()->exists()
            || $account->signupRequests()->exists();
        $hasIntegrationData = IntegrationSetting::query()->whereBelongsTo($account)->exists()
            || AccountAiProviderCredential::query()->whereBelongsTo($account)->exists()
            || TelegramBotInstallation::query()->whereBelongsTo($account)->exists();
        $hasTokenOrFiscalData = $account->apiTokens()->exists()
            || $account->fiscalReceipts()->exists();
        $hasLivePurchaseProvider = $account->customerPurchases()
            ->where(function ($query): void {
                $query->whereNull('provider')
                    ->orWhere('provider', '!=', CustomerPurchase::ProviderStudioCash);
            })
            ->exists();
        $hasNonDemoEventProvider = $account->eventOrders()
            ->where(function ($query): void {
                $query->whereNull('provider')
                    ->orWhere('provider', '!=', DemoStudioFixture::ShowcaseEventProvider);
            })
            ->exists();

        if ($hasSubscriptionData || $hasIntegrationData || $hasTokenOrFiscalData || $hasLivePurchaseProvider || $hasNonDemoEventProvider) {
            throw new RuntimeException('The demo studio has subscription, integration, token, fiscal, or non-demo provider records.');
        }
    }

    /**
     * @return array{
     *     account_id: int,
     *     account_slug: string,
     *     owner_email: string,
     *     resources: array<string, array{create: int, update: int, noop: int}>,
     *     event_slugs: array<int, string>,
     *     order_ids: array<int, string>,
     *     ticket_codes: array<int, string>
     * }
     */
    private function buildPlan(Account $account, User $owner): array
    {
        $resources = collect([
            'account_settings',
            'internal_class_types',
            'internal_classes',
            'additional_trainers',
            'events',
            'event_rooms',
            'ticket_types',
            'orders',
            'order_items',
            'tickets',
            'check_ins',
        ])->mapWithKeys(fn (string $resource): array => [
            $resource => ['create' => 0, 'update' => 0, 'noop' => 0],
        ])->all();

        $accountAttributes = $this->accountSettings($account);
        $this->recordState($resources, 'account_settings', $account, $accountAttributes);

        $classTypes = [];
        foreach (DemoStudioFixture::internalClassTypes() as $slug => $data) {
            $classType = $account->classTypes()->where('slug', $slug)->first();

            if ($classType && $classType->schedule_kind !== ScheduleKind::InternalClass) {
                throw new RuntimeException("Internal class type slug collision [{$slug}].");
            }

            $this->recordState($resources, 'internal_class_types', $classType, $this->classTypeAttributes($data));
            $classTypes[$slug] = $classType;
        }

        foreach (DemoStudioFixture::internalClassOccurrences() as $key => $data) {
            $scheduledClass = ScheduledClass::query()
                ->where(DemoStudioFixture::showcaseMetadataKeyPath(), $key)
                ->first();

            if ($scheduledClass && $scheduledClass->account_id !== $account->id) {
                throw new RuntimeException("Scheduled class metadata collision [{$key}].");
            }

            if ($scheduledClass && ($scheduledClass->schedule_series_id !== null
                || $scheduledClass->class_type_id !== $classTypes[$data['class_type']]?->id)) {
                throw new RuntimeException("Scheduled class relationship collision [{$key}].");
            }

            $attributes = $classTypes[$data['class_type']]
                ? $this->scheduledClassAttributes($account, $key, $data, $classTypes[$data['class_type']])
                : [];
            $this->recordState($resources, 'internal_classes', $scheduledClass, $attributes);

            if (! $scheduledClass) {
                $resources['additional_trainers']['create'] += count($data['additional_trainers']);
            } else {
                $expectedTrainerIds = $account->trainers()
                    ->whereIn('slug', $data['additional_trainers'])
                    ->pluck('id')
                    ->all();
                $existingTrainerIds = $scheduledClass->additionalTrainers()->pluck('trainers.id')->all();

                foreach ($expectedTrainerIds as $trainerId) {
                    $resources['additional_trainers'][in_array($trainerId, $existingTrainerIds, true) ? 'noop' : 'create']++;
                }
            }
        }

        $this->assertDeterministicIdentifierCollisions($account);
        $location = $this->showcaseLocation($account);
        $room = $this->showcaseRoom($account);

        foreach (DemoStudioFixture::showcaseEvents() as $slug => $eventData) {
            $event = $account->events()->where('slug', $slug)->first();
            $this->recordState($resources, 'events', $event, $this->eventAttributes($account, $location->id, $slug, $eventData));

            if (! $event) {
                $resources['event_rooms']['create']++;
                $resources['ticket_types']['create'] += count($eventData['ticket_types']);
                $resources['orders']['create'] += count($eventData['orders']);
                $resources['order_items']['create'] += count($eventData['orders']);

                foreach ($eventData['orders'] as $orderData) {
                    $resources['tickets']['create'] += count($orderData['tickets']);
                    $resources['check_ins']['create'] += collect($orderData['tickets'])->whereNotNull('checked_in_at')->count();
                }

                continue;
            }

            $roomAttached = $event->rooms()->whereKey($room->id)->exists();
            $resources['event_rooms'][$roomAttached ? 'noop' : 'create']++;
            $ticketTypes = [];

            foreach ($eventData['ticket_types'] as $ticketTypeKey => $ticketTypeData) {
                $ticketType = $event->ticketTypes()->where('sort_order', $ticketTypeData['sort_order'])->first();

                if ($ticketType && $ticketType->name !== $ticketTypeData['name']) {
                    throw new RuntimeException("Ticket type position collision [{$slug}:{$ticketTypeData['sort_order']}].");
                }

                $this->recordState($resources, 'ticket_types', $ticketType, $this->ticketTypeAttributes($account, $event, $ticketTypeData));
                $ticketTypes[$ticketTypeKey] = $ticketType;
            }

            foreach ($eventData['orders'] as $orderKey => $orderData) {
                $orderId = $this->orderId($orderKey);
                $order = EventOrder::query()->where('order_id', $orderId)->first();
                $this->assertOrderScope($account, $event, $order, $orderId);
                $this->recordState($resources, 'orders', $order, $this->orderAttributes($account, $owner, $event, $orderKey, $orderData));

                if (! $order) {
                    $resources['order_items']['create']++;
                    $resources['tickets']['create'] += count($orderData['tickets']);
                    $resources['check_ins']['create'] += collect($orderData['tickets'])->whereNotNull('checked_in_at')->count();

                    continue;
                }

                $items = $order->items()->get();

                if ($items->count() > 1) {
                    throw new RuntimeException("Order item collision [{$orderId}].");
                }

                $ticketType = $ticketTypes[$orderData['ticket_type']];
                $item = $items->first();

                if ($item && $ticketType && $item->event_ticket_type_id !== $ticketType->id) {
                    throw new RuntimeException("Order item relationship collision [{$orderId}].");
                }

                $itemAttributes = $ticketType
                    ? $this->orderItemAttributes($account, $event, $order, $ticketType, $orderData)
                    : [];
                $this->recordState($resources, 'order_items', $item, $itemAttributes);

                foreach ($orderData['tickets'] as $index => $ticketData) {
                    $code = $this->ticketCode($slug, $orderKey, $index);
                    $ticket = EventTicket::query()->where('code', $code)->first();
                    $this->assertTicketScope($account, $event, $order, $ticket, $code);
                    $ticketAttributes = ($item && $ticketType)
                        ? $this->ticketAttributes($account, $owner, $event, $order, $item, $ticketType, $slug, $orderKey, $index, $ticketData)
                        : [];
                    $this->recordState($resources, 'tickets', $ticket, $ticketAttributes);

                    if ($ticketData['checked_in_at'] === null) {
                        continue;
                    }

                    $checkIns = $ticket?->checkIns()->where('action', 'check_in')->get() ?? collect();

                    if ($checkIns->count() > 1) {
                        throw new RuntimeException("Ticket check-in collision [{$code}].");
                    }

                    $checkInAttributes = $ticket
                        ? $this->checkInAttributes($account, $owner, $event, $ticket, $ticketData['checked_in_at'])
                        : [];
                    $this->recordState($resources, 'check_ins', $checkIns->first(), $checkInAttributes);
                }
            }
        }

        return [
            'account_id' => $account->id,
            'account_slug' => $account->slug,
            'owner_email' => $owner->email,
            'resources' => $resources,
            'event_slugs' => array_keys(DemoStudioFixture::showcaseEvents()),
            'order_ids' => $this->expectedOrderIds(),
            'ticket_codes' => $this->expectedTicketCodes(),
        ];
    }

    /**
     * @param  array<string, array{create: int, update: int, noop: int}>  $resources
     * @param  array<string, mixed>  $attributes
     */
    private function recordState(array &$resources, string $resource, ?Model $model, array $attributes): void
    {
        if (! $model) {
            $resources[$resource]['create']++;

            return;
        }

        $resources[$resource][$this->wouldChange($model, $attributes) ? 'update' : 'noop']++;
    }

    /** @param array<string, mixed> $attributes */
    private function wouldChange(Model $model, array $attributes): bool
    {
        $candidate = clone $model;
        $candidate->fill($attributes);

        return $candidate->isDirty();
    }

    private function synchronizeAccountSettings(Account $account): void
    {
        $account->fill($this->accountSettings($account));

        if ($account->isDirty()) {
            $account->saveQuietly();
        }
    }

    /**
     * @return array<string, ClassType>
     */
    private function synchronizeInternalClassTypes(Account $account): array
    {
        $classTypes = [];

        foreach (DemoStudioFixture::internalClassTypes() as $slug => $data) {
            $classType = $account->classTypes()->firstOrNew(['slug' => $slug]);

            if ($classType->exists && $classType->schedule_kind !== ScheduleKind::InternalClass) {
                throw new RuntimeException("Internal class type slug collision [{$slug}].");
            }

            $classType->fill($this->classTypeAttributes($data));

            if (! $classType->exists || $classType->isDirty()) {
                $classType->saveQuietly();
            }

            $classTypes[$slug] = $classType;
        }

        return $classTypes;
    }

    /**
     * @param  array<string, ClassType>  $classTypes
     */
    private function synchronizeInternalClasses(Account $account, array $classTypes): void
    {
        $location = $this->showcaseLocation($account);
        $rooms = $account->rooms()->whereIn('slug', array_keys(DemoStudioFixture::rooms()))->get()->keyBy('slug');
        $trainers = $account->trainers()->whereIn('slug', array_keys(DemoStudioFixture::trainers()))->get()->keyBy('slug');

        foreach (DemoStudioFixture::internalClassOccurrences() as $key => $data) {
            $scheduledClass = ScheduledClass::query()
                ->where(DemoStudioFixture::showcaseMetadataKeyPath(), $key)
                ->first();

            if ($scheduledClass && ($scheduledClass->account_id !== $account->id
                || $scheduledClass->schedule_series_id !== null
                || $scheduledClass->class_type_id !== $classTypes[$data['class_type']]->id)) {
                throw new RuntimeException("Scheduled class collision [{$key}].");
            }

            $scheduledClass ??= $account->scheduledClasses()->make();
            $scheduledClass->fill($this->scheduledClassAttributes(
                $account,
                $key,
                $data,
                $classTypes[$data['class_type']],
                $location->id,
                $rooms[$data['room']]?->id,
                $trainers[$data['trainer']]?->id,
            ));

            if (! $scheduledClass->exists || $scheduledClass->isDirty()) {
                $scheduledClass->saveQuietly();
            }

            $additionalTrainerIds = collect($data['additional_trainers'])
                ->map(fn (string $trainerSlug): int => $trainers[$trainerSlug]?->id
                    ?? throw new RuntimeException("Missing demo trainer [{$trainerSlug}]."))
                ->all();
            $existingTrainerIds = $scheduledClass->additionalTrainers()->pluck('trainers.id');
            $missingTrainerIds = collect($additionalTrainerIds)->diff($existingTrainerIds);

            if ($missingTrainerIds->isNotEmpty()) {
                $scheduledClass->additionalTrainers()->syncWithoutDetaching(
                    $missingTrainerIds->mapWithKeys(fn (int $trainerId): array => [
                        $trainerId => ['account_id' => $account->id],
                    ])->all(),
                );
            }
        }
    }

    private function synchronizeEvents(Account $account, User $owner): void
    {
        $location = $this->showcaseLocation($account);
        $room = $this->showcaseRoom($account);

        foreach (DemoStudioFixture::showcaseEvents() as $slug => $eventData) {
            $event = $account->events()->firstOrNew(['slug' => $slug]);
            $event->fill($this->eventAttributes($account, $location->id, $slug, $eventData));

            if (! $event->exists || $event->isDirty()) {
                $event->saveQuietly();
            }

            if (! $event->rooms()->whereKey($room->id)->exists()) {
                $event->rooms()->attach($room->id, ['account_id' => $account->id]);
            }

            $ticketTypes = [];
            foreach ($eventData['ticket_types'] as $ticketTypeKey => $ticketTypeData) {
                $ticketType = $event->ticketTypes()->where('sort_order', $ticketTypeData['sort_order'])->first()
                    ?? $event->ticketTypes()->make();

                if ($ticketType->exists && $ticketType->name !== $ticketTypeData['name']) {
                    throw new RuntimeException("Ticket type position collision [{$slug}:{$ticketTypeData['sort_order']}].");
                }

                $ticketType->fill($this->ticketTypeAttributes($account, $event, $ticketTypeData));

                if (! $ticketType->exists || $ticketType->isDirty()) {
                    $ticketType->saveQuietly();
                }

                $ticketTypes[$ticketTypeKey] = $ticketType;
            }

            foreach ($eventData['orders'] as $orderKey => $orderData) {
                $orderId = $this->orderId($orderKey);
                $order = EventOrder::query()->where('order_id', $orderId)->first();
                $this->assertOrderScope($account, $event, $order, $orderId);
                $order ??= $event->orders()->make();
                $order->fill($this->orderAttributes($account, $owner, $event, $orderKey, $orderData));

                if (! $order->exists) {
                    $order->access_token_encrypted = $this->orderAccessToken($orderKey);
                }

                if (! $order->exists || $order->isDirty()) {
                    $order->saveQuietly();
                }

                $items = $order->items()->get();

                if ($items->count() > 1) {
                    throw new RuntimeException("Order item collision [{$orderId}].");
                }

                $ticketType = $ticketTypes[$orderData['ticket_type']];
                $item = $items->first() ?? $order->items()->make();

                if ($item->exists && $item->event_ticket_type_id !== $ticketType->id) {
                    throw new RuntimeException("Order item relationship collision [{$orderId}].");
                }

                $item->fill($this->orderItemAttributes($account, $event, $order, $ticketType, $orderData));

                if (! $item->exists || $item->isDirty()) {
                    $item->saveQuietly();
                }

                foreach ($orderData['tickets'] as $index => $ticketData) {
                    $code = $this->ticketCode($slug, $orderKey, $index);
                    $ticket = EventTicket::query()->where('code', $code)->first();
                    $this->assertTicketScope($account, $event, $order, $ticket, $code);
                    $ticket ??= $order->tickets()->make();
                    $ticket->fill($this->ticketAttributes(
                        $account,
                        $owner,
                        $event,
                        $order,
                        $item,
                        $ticketType,
                        $slug,
                        $orderKey,
                        $index,
                        $ticketData,
                    ));

                    if (! $ticket->exists) {
                        $ticket->token_encrypted = $this->ticketToken($slug, $orderKey, $index);
                    }

                    if (! $ticket->exists || $ticket->isDirty()) {
                        $ticket->saveQuietly();
                    }

                    if ($ticketData['checked_in_at'] !== null) {
                        $this->synchronizeCheckIn($account, $owner, $event, $ticket, $ticketData['checked_in_at']);
                    }
                }
            }
        }
    }

    private function synchronizeCheckIn(Account $account, User $owner, Event $event, EventTicket $ticket, string $checkedInAt): void
    {
        $checkIns = $ticket->checkIns()->where('action', 'check_in')->get();

        if ($checkIns->count() > 1) {
            throw new RuntimeException("Ticket check-in collision [{$ticket->code}].");
        }

        $checkIn = $checkIns->first() ?? $ticket->checkIns()->make();
        $checkIn->fill($this->checkInAttributes($account, $owner, $event, $ticket, $checkedInAt));

        if (! $checkIn->exists || $checkIn->isDirty()) {
            $checkIn->saveQuietly();
        }
    }

    /** @return array<string, mixed> */
    private function accountSettings(Account $account): array
    {
        $enabledKinds = collect($account->enabled_schedule_kinds ?? ScheduleKindRegistry::defaultEnabledValues())
            ->push(ScheduleKind::InternalClass->value)
            ->unique()
            ->values()
            ->all();
        $colors = [
            ...($account->schedule_kind_colors ?? []),
            ScheduleKind::InternalClass->value => '#D7A94A',
        ];

        return [
            'enabled_schedule_kinds' => $enabledKinds,
            'schedule_kind_colors' => $colors,
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function classTypeAttributes(array $data): array
    {
        return [
            'activity_direction_id' => null,
            'name' => $data['name'],
            'description' => $data['description'],
            'color' => $data['color'],
            'schedule_kind' => ScheduleKind::InternalClass,
            'default_duration_minutes' => $data['duration'],
            'booking_cutoff_minutes' => null,
            'cancellation_cutoff_minutes' => null,
            'default_capacity' => null,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scheduledClassAttributes(
        Account $account,
        string $showcaseKey,
        array $data,
        ClassType $classType,
        ?int $locationId = null,
        ?int $roomId = null,
        ?int $trainerId = null,
    ): array {
        $locationId ??= $this->showcaseLocation($account)->id;
        $roomId ??= $account->rooms()->where('slug', $data['room'])->value('id');
        $trainerId ??= $account->trainers()->where('slug', $data['trainer'])->value('id');

        if (! $roomId || ! $trainerId) {
            throw new RuntimeException('The demo studio is missing a required showcase room or trainer.');
        }

        $startsAt = CarbonImmutable::parse($data['starts_at'], $account->timezone)->utc();

        return [
            'location_id' => $locationId,
            'room_id' => $roomId,
            'class_type_id' => $classType->id,
            'trainer_id' => $trainerId,
            'schedule_series_id' => null,
            'title' => $classType->name,
            'description' => $data['description'],
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($classType->default_duration_minutes),
            'capacity' => null,
            'booking_cutoff_minutes' => null,
            'cancellation_cutoff_minutes' => null,
            'is_generated' => false,
            'is_manually_modified' => false,
            'metadata' => [
                'source' => 'demo_showcase',
                'schedule_kind' => ScheduleKind::InternalClass->value,
                DemoStudioFixture::ShowcaseMetadataKey => $showcaseKey,
            ],
            'is_public' => false,
            'status' => ScheduledClassStatus::Scheduled,
        ];
    }

    /** @param array<string, mixed> $eventData @return array<string, mixed> */
    private function eventAttributes(Account $account, int $locationId, string $slug, array $eventData): array
    {
        return [
            'account_id' => $account->id,
            'location_id' => $locationId,
            'slug' => $slug,
            'status' => EventStatus::from($eventData['status']),
            'title' => $eventData['title'],
            'summary' => $eventData['summary'],
            'description_html' => $eventData['description_html'],
            'rules_html' => '<p>Синтетична демонстраційна подія. Дані квитків і покупців не належать реальним людям.</p>',
            'venue_kind' => EventVenueKind::Studio,
            'external_venue_name' => null,
            'external_address' => null,
            'external_map_url' => null,
            'external_directions' => null,
            'starts_at' => CarbonImmutable::parse($eventData['starts_at'], $account->timezone)->utc(),
            'ends_at' => CarbonImmutable::parse($eventData['ends_at'], $account->timezone)->utc(),
            'timezone' => $account->timezone,
            'currency' => 'UAH',
            'capacity' => $eventData['capacity'],
            'published_at' => $this->fixtureDate($eventData['published_at'], $account),
            'cancelled_at' => $this->fixtureDate($eventData['cancelled_at'], $account),
            'archived_at' => null,
        ];
    }

    /** @param array<string, mixed> $ticketTypeData @return array<string, mixed> */
    private function ticketTypeAttributes(Account $account, Event $event, array $ticketTypeData): array
    {
        return [
            'account_id' => $account->id,
            'event_id' => $event->id,
            'name' => $ticketTypeData['name'],
            'description' => $ticketTypeData['description'],
            'inventory' => $ticketTypeData['inventory'],
            'price_cents' => $ticketTypeData['price_cents'],
            'early_bird_price_cents' => $ticketTypeData['early_bird_price_cents'] ?? null,
            'early_bird_ends_at' => $this->fixtureDate($ticketTypeData['early_bird_ends_at'] ?? null, $account),
            'early_bird_quota' => $ticketTypeData['early_bird_quota'] ?? null,
            'sales_starts_at' => null,
            'sales_ends_at' => $event->starts_at,
            'max_per_order' => 10,
            'is_active' => true,
            'sort_order' => $ticketTypeData['sort_order'],
        ];
    }

    /** @param array<string, mixed> $orderData @return array<string, mixed> */
    private function orderAttributes(Account $account, User $owner, Event $event, string $orderKey, array $orderData): array
    {
        $accessToken = $this->orderAccessToken($orderKey);
        $isRefunded = $orderData['status'] === EventOrderStatus::Refunded->value;

        return [
            'account_id' => $account->id,
            'event_id' => $event->id,
            'provider' => DemoStudioFixture::ShowcaseEventProvider,
            'order_id' => $this->orderId($orderKey),
            'status' => EventOrderStatus::from($orderData['status']),
            'buyer_name' => $orderData['buyer_name'],
            'buyer_email' => $orderData['buyer_email'],
            'buyer_phone' => '+380000000000',
            'locale' => 'uk',
            'amount_cents' => $orderData['amount_cents'],
            'currency' => 'UAH',
            'access_token_hash' => hash('sha256', $accessToken),
            'gateway_invoice_id' => null,
            'gateway_payment_id' => null,
            'gateway_status' => 'synthetic_demo',
            'gateway_checkout_payload' => null,
            'last_callback_payload' => null,
            'failure_reason' => $orderData['status'] === EventOrderStatus::RefundRequired->value
                ? 'Синтетичне скасування події: потрібна увага до повернення.'
                : null,
            'expires_at' => null,
            'paid_at' => $this->fixtureDate($orderData['paid_at'], $account),
            'failed_at' => null,
            'terms_accepted_at' => $this->fixtureDate($orderData['paid_at'], $account),
            'terms_hash' => hash('sha256', 'ladna-demo-showcase-terms-v1'),
            'refunded_by' => $isRefunded ? $owner->id : null,
            'refunded_at' => $this->fixtureDate($orderData['refunded_at'] ?? null, $account),
            'refund_reason' => $isRefunded ? 'Синтетичне повернення для демонстрації життєвого циклу квитка.' : null,
        ];
    }

    /** @param array<string, mixed> $orderData @return array<string, mixed> */
    private function orderItemAttributes(
        Account $account,
        Event $event,
        EventOrder $order,
        EventTicketType $ticketType,
        array $orderData,
    ): array {
        return [
            'account_id' => $account->id,
            'event_id' => $event->id,
            'event_order_id' => $order->id,
            'event_ticket_type_id' => $ticketType->id,
            'ticket_type_name' => $ticketType->name,
            'ticket_type_description' => $ticketType->description,
            'price_tier' => $orderData['price_tier'] ?? 'regular',
            'unit_price_cents' => intdiv($orderData['amount_cents'], $orderData['quantity']),
            'quantity' => $orderData['quantity'],
            'total_cents' => $orderData['amount_cents'],
        ];
    }

    /** @param array<string, mixed> $ticketData @return array<string, mixed> */
    private function ticketAttributes(
        Account $account,
        User $owner,
        Event $event,
        EventOrder $order,
        EventOrderItem $item,
        EventTicketType $ticketType,
        string $eventSlug,
        string $orderKey,
        int $index,
        array $ticketData,
    ): array {
        $token = $this->ticketToken($eventSlug, $orderKey, $index);
        $isVoided = $ticketData['status'] === EventTicketStatus::Voided->value;
        $checkedInAt = $this->fixtureDate($ticketData['checked_in_at'], $account);

        return [
            'account_id' => $account->id,
            'event_id' => $event->id,
            'event_order_id' => $order->id,
            'event_order_item_id' => $item->id,
            'event_ticket_type_id' => $ticketType->id,
            'code' => $this->ticketCode($eventSlug, $orderKey, $index),
            'token_hash' => hash('sha256', $token),
            'status' => EventTicketStatus::from($ticketData['status']),
            'is_checked_in' => $checkedInAt !== null,
            'checked_in_at' => $checkedInAt,
            'voided_by' => $isVoided ? $owner->id : null,
            'voided_at' => $isVoided ? $event->cancelled_at : null,
            'void_reason' => $isVoided ? 'Синтетичне скасування події.' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function checkInAttributes(Account $account, User $owner, Event $event, EventTicket $ticket, string $checkedInAt): array
    {
        return [
            'account_id' => $account->id,
            'event_id' => $event->id,
            'event_ticket_id' => $ticket->id,
            'user_id' => $owner->id,
            'action' => 'check_in',
            'source' => 'door_list',
            'actor_name' => $owner->name,
            'actor_email' => $owner->email,
            'reason' => 'Синтетична відмітка на вході.',
            'occurred_at' => CarbonImmutable::parse($checkedInAt, $account->timezone)->utc(),
        ];
    }

    private function showcaseLocation(Account $account): Location
    {
        return $account->locations()->where('slug', DemoStudioFixture::location()['slug'])->first()
            ?? throw new RuntimeException('The demo studio location is missing.');
    }

    private function showcaseRoom(Account $account): Room
    {
        return $account->rooms()->where('slug', 'lavender-hall')->first()
            ?? throw new RuntimeException('The demo studio showcase room is missing.');
    }

    private function fixtureDate(?string $value, Account $account): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, $account->timezone)->utc();
    }

    private function orderId(string $orderKey): string
    {
        return "ladna-demo-event-{$orderKey}";
    }

    private function orderAccessToken(string $orderKey): string
    {
        return hash_hmac('sha256', "ladna-demo-showcase-order:{$orderKey}", (string) config('app.key'));
    }

    private function ticketToken(string $eventSlug, string $orderKey, int $index): string
    {
        return hash_hmac('sha256', "ladna-demo-showcase-ticket:{$eventSlug}:{$orderKey}:{$index}", (string) config('app.key'));
    }

    private function ticketCode(string $eventSlug, string $orderKey, int $index): string
    {
        $hash = strtoupper(hash('sha256', "{$eventSlug}:{$orderKey}:{$index}"));

        return 'LDS-'.substr($hash, 0, 4).'-'.substr($hash, 4, 4);
    }

    /** @return array<int, string> */
    private function expectedOrderIds(): array
    {
        return collect(DemoStudioFixture::showcaseEvents())
            ->flatMap(fn (array $eventData): array => array_keys($eventData['orders']))
            ->map(fn (string $orderKey): string => $this->orderId($orderKey))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function expectedTicketCodes(): array
    {
        return collect(DemoStudioFixture::showcaseEvents())
            ->flatMap(function (array $eventData, string $eventSlug): array {
                return collect($eventData['orders'])->flatMap(
                    fn (array $orderData, string $orderKey): array => collect($orderData['tickets'])
                        ->keys()
                        ->map(fn (int $index): string => $this->ticketCode($eventSlug, $orderKey, $index))
                        ->all(),
                )->all();
            })
            ->values()
            ->all();
    }

    private function assertDeterministicIdentifierCollisions(Account $account): void
    {
        $eventSlugs = array_keys(DemoStudioFixture::showcaseEvents());
        $knownEventIds = $account->events()->whereIn('slug', $eventSlugs)->pluck('id');

        $orderCollision = EventOrder::query()
            ->whereIn('order_id', $this->expectedOrderIds())
            ->where(function ($query) use ($account, $knownEventIds): void {
                $query->where('account_id', '!=', $account->id)
                    ->orWhereNotIn('event_id', $knownEventIds);
            })
            ->exists();
        $ticketCollision = EventTicket::query()
            ->whereIn('code', $this->expectedTicketCodes())
            ->where(function ($query) use ($account, $knownEventIds): void {
                $query->where('account_id', '!=', $account->id)
                    ->orWhereNotIn('event_id', $knownEventIds);
            })
            ->exists();
        $orderTokenCollision = EventOrder::query()
            ->whereIn('access_token_hash', $this->expectedOrderTokenHashes())
            ->where(function ($query) use ($account): void {
                $query->where('account_id', '!=', $account->id)
                    ->orWhereNotIn('order_id', $this->expectedOrderIds());
            })
            ->exists();
        $ticketTokenCollision = EventTicket::query()
            ->whereIn('token_hash', $this->expectedTicketTokenHashes())
            ->where(function ($query) use ($account): void {
                $query->where('account_id', '!=', $account->id)
                    ->orWhereNotIn('code', $this->expectedTicketCodes());
            })
            ->exists();

        if ($orderCollision || $ticketCollision || $orderTokenCollision || $ticketTokenCollision) {
            throw new RuntimeException('A deterministic showcase order or ticket identifier is already used outside its expected demo event.');
        }
    }

    /** @return array<int, string> */
    private function expectedOrderTokenHashes(): array
    {
        return collect(DemoStudioFixture::showcaseEvents())
            ->flatMap(fn (array $eventData): array => array_keys($eventData['orders']))
            ->map(fn (string $orderKey): string => hash('sha256', $this->orderAccessToken($orderKey)))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function expectedTicketTokenHashes(): array
    {
        return collect(DemoStudioFixture::showcaseEvents())
            ->flatMap(function (array $eventData, string $eventSlug): array {
                return collect($eventData['orders'])->flatMap(
                    fn (array $orderData, string $orderKey): array => collect($orderData['tickets'])
                        ->keys()
                        ->map(fn (int $index): string => hash('sha256', $this->ticketToken($eventSlug, $orderKey, $index)))
                        ->all(),
                )->all();
            })
            ->values()
            ->all();
    }

    private function assertOrderScope(Account $account, Event $event, ?EventOrder $order, string $orderId): void
    {
        if ($order && ($order->account_id !== $account->id
            || $order->event_id !== $event->id
            || $order->provider !== DemoStudioFixture::ShowcaseEventProvider)) {
            throw new RuntimeException("Order identifier collision [{$orderId}].");
        }
    }

    private function assertTicketScope(Account $account, Event $event, EventOrder $order, ?EventTicket $ticket, string $code): void
    {
        if ($ticket && ($ticket->account_id !== $account->id
            || $ticket->event_id !== $event->id
            || $ticket->event_order_id !== $order->id)) {
            throw new RuntimeException("Ticket code collision [{$code}].");
        }
    }
}
