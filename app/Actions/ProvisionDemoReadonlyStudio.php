<?php

namespace App\Actions;

use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\ScheduleSeriesStatus;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassPassSegment;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseCorrection;
use App\Models\ExpenseCategory;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassPeopleCount;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use App\Models\WebsiteLead;
use App\Support\DemoStudioFixture;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProvisionDemoReadonlyStudio
{
    public function __construct(
        private readonly GenerateAccountSchedule $generateAccountSchedule,
    ) {}

    /** @return array{operation: string, account_slug: string, owner_email: string, customers: int, trainers: int, schedule_series: int, people_counter_samples: int} */
    public function preview(bool $refresh): array
    {
        $state = $this->validatedState($refresh);

        return [
            'operation' => $state['account'] ? 'refresh' : 'create',
            'account_slug' => DemoStudioFixture::AccountSlug,
            'owner_email' => $state['credentials']['email'],
            'customers' => count(DemoStudioFixture::customerNames()),
            'trainers' => count(DemoStudioFixture::trainers()),
            'schedule_series' => count(DemoStudioFixture::scheduleRows()),
            'people_counter_samples' => DemoStudioFixture::PeopleCounterSampleCount,
        ];
    }

    public function execute(bool $refresh): Account
    {
        return DB::transaction(function () use ($refresh): Account {
            $state = $this->validatedState($refresh, true);
            $owner = $state['owner'];

            if ($state['account']) {
                $state['account']->studioCashEntries()->delete();
                $state['account']->delete();
            }

            $owner = $this->owner($owner, $state['credentials']);
            $account = Account::query()->create(DemoStudioFixture::account());
            $account->users()->attach($owner->id, [
                'role' => AccountRole::Owner->value,
                'permissions' => null,
            ]);

            $this->populate($account, $owner);

            return $account->fresh();
        }, 3);
    }

    /**
     * @return array{account: Account|null, owner: User|null, credentials: array{name: string, email: string, password: string}}
     */
    private function validatedState(bool $refresh, bool $lock = false): array
    {
        $credentials = $this->ownerCredentials();
        $accountQuery = Account::query()->where('slug', DemoStudioFixture::AccountSlug);
        $ownerQuery = User::query()->where('email', $credentials['email']);

        if ($lock) {
            $accountQuery->lockForUpdate();
            $ownerQuery->lockForUpdate();
        }

        $account = $accountQuery->first();
        $owner = $ownerQuery->first();

        if (! $account) {
            if ($refresh) {
                throw new RuntimeException('The demo studio does not exist, so it cannot be refreshed.');
            }

            if ($owner) {
                throw new RuntimeException('The demo owner email is already used by an unrelated user.');
            }

            return compact('account', 'owner', 'credentials');
        }

        $this->assertRefreshIsSafe($account, $owner, $credentials['email']);

        if (! $refresh) {
            throw new RuntimeException('The demo studio already exists. Use --refresh with --execute to replace it.');
        }

        return compact('account', 'owner', 'credentials');
    }

    /** @return array{name: string, email: string, password: string} */
    private function ownerCredentials(): array
    {
        $credentials = config('demo-studio.owner');

        if (! is_array($credentials)) {
            throw new RuntimeException('Demo studio owner credentials are not configured.');
        }

        foreach (['name', 'email', 'password'] as $field) {
            if (! is_string($credentials[$field] ?? null) || blank($credentials[$field])) {
                throw new RuntimeException("Demo studio owner {$field} is not configured.");
            }
        }

        if (! filter_var($credentials['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Demo studio owner email is invalid.');
        }

        return $credentials;
    }

    private function assertRefreshIsSafe(Account $account, ?User $owner, string $email): void
    {
        if (! $account->isReadOnlyDemo()) {
            throw new RuntimeException('Refusing to replace a live account that uses the demo slug.');
        }

        $memberships = $account->memberships()->with('user')->get();

        if ($memberships->count() !== 1
            || $memberships->first()?->role !== AccountRole::Owner
            || $memberships->first()?->user?->email !== $email
            || ! $owner
            || $owner->accountMemberships()->count() !== 1) {
            throw new RuntimeException('The demo studio has unexpected owner memberships.');
        }

        $hasProviderData = $account->subscription()->exists()
            || $account->subscriptionPayments()->exists()
            || $account->signupRequests()->exists()
            || $account->apiTokens()->exists()
            || $account->fiscalReceipts()->exists()
            || IntegrationSetting::query()->where('account_id', $account->id)->exists()
            || $account->customerPurchases()->where(function ($query): void {
                $query->whereNull('provider')
                    ->orWhere('provider', '!=', CustomerPurchase::ProviderStudioCash);
            })->exists();

        if ($hasProviderData) {
            throw new RuntimeException('The demo studio has provider, signup, integration, token, or fiscal records.');
        }
    }

    /** @param array{name: string, email: string, password: string} $credentials */
    private function owner(?User $owner, array $credentials): User
    {
        $owner ??= new User;
        $owner->forceFill([
            'name' => $credentials['name'],
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'system_role' => null,
            'email_verified_at' => now(),
        ])->save();

        return $owner;
    }

    private function populate(Account $account, User $owner): void
    {
        $location = $account->locations()->create(DemoStudioFixture::location());
        $rooms = $this->rooms($account, $location);
        $directions = $this->directions($account);
        $trainerTypes = $this->trainerTypes($account);
        $trainers = $this->trainers($account, $location, $directions, $trainerTypes);
        $classTypes = $this->classTypes($account, $directions);
        $segments = $this->segments($account, $directions);
        $plans = $this->plans($account, $rooms, $classTypes, $trainerTypes, $segments);
        $customers = $this->customers($account);

        $this->schedule($account, $location, $rooms, $classTypes, $trainers);
        $this->bookingsAndPasses($account, $owner, $location, $rooms, $classTypes, $trainers, $plans, $customers);
        $this->peopleCounter($account, $owner, $location, $rooms, $customers);
        $this->leads($account);
        $this->cashflow($account, $owner, $location, $plans, $customers);
    }

    /** @return array<string, Room> */
    private function rooms(Account $account, Location $location): array
    {
        return collect(DemoStudioFixture::rooms())->mapWithKeys(
            fn (array $room, string $slug): array => [$slug => $account->rooms()->create([
                'location_id' => $location->id,
                'slug' => $slug,
                ...$room,
            ])],
        )->all();
    }

    /** @return array<string, ActivityDirection> */
    private function directions(Account $account): array
    {
        return collect(DemoStudioFixture::directions())->mapWithKeys(
            fn (array $direction, string $slug): array => [$slug => $account->activityDirections()->create([
                'slug' => $slug,
                ...$direction,
            ])],
        )->all();
    }

    /** @return array<string, TrainerType> */
    private function trainerTypes(Account $account): array
    {
        return collect(DemoStudioFixture::trainerTypes())->mapWithKeys(
            fn (array $type, string $key): array => [$key => $account->trainerTypes()->create($type)],
        )->all();
    }

    /**
     * @param  array<string, ActivityDirection>  $directions
     * @param  array<string, TrainerType>  $trainerTypes
     * @return array<string, Trainer>
     */
    private function trainers(Account $account, Location $location, array $directions, array $trainerTypes): array
    {
        return collect(DemoStudioFixture::trainers())->mapWithKeys(function (array $data, string $slug) use ($account, $location, $directions, $trainerTypes): array {
            $trainer = $account->trainers()->create([
                'trainer_type_id' => $trainerTypes[$data['trainer_type']]->id,
                'name' => $data['name'],
                'slug' => $slug,
                'email' => $data['email'],
                'phone' => $data['phone'],
                'bio' => 'Тренерка синтетичної Ladna Demo Studio.',
                'photo_path' => null,
                'is_active' => true,
            ]);
            $trainer->locations()->attach($location->id, ['account_id' => $account->id]);
            $trainer->activityDirections()->attach(
                collect($directions)->take(2)->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['account_id' => $account->id]])->all(),
            );

            return [$slug => $trainer];
        })->all();
    }

    /** @param array<string, ActivityDirection> $directions @return array<string, ClassType> */
    private function classTypes(Account $account, array $directions): array
    {
        return collect(DemoStudioFixture::classTypes())->mapWithKeys(function (array $data, string $slug) use ($account, $directions): array {
            $classType = $account->classTypes()->create([
                'activity_direction_id' => $data['direction'] ? $directions[$data['direction']]->id : null,
                'name' => $data['name'],
                'slug' => $slug,
                'description' => 'Синтетичний формат для демонстрації Ladna.',
                'color' => $data['color'],
                'schedule_kind' => $data['schedule_kind'],
                'default_duration_minutes' => $data['duration'],
                'booking_cutoff_minutes' => 60,
                'cancellation_cutoff_minutes' => 720,
                'default_capacity' => $data['capacity'],
                'is_active' => true,
            ]);

            return [$slug => $classType];
        })->all();
    }

    /** @param array<string, ActivityDirection> $directions @return array<string, ClassPassSegment> */
    private function segments(Account $account, array $directions): array
    {
        return collect(DemoStudioFixture::classPassSegments())->mapWithKeys(function (array $data, string $slug) use ($account, $directions): array {
            $segment = $account->classPassSegments()->create([
                'name' => $data['name'],
                'slug' => $slug,
                'schedule_kind' => $data['schedule_kind'],
                'sort_order' => $data['sort_order'],
                'is_active' => true,
            ]);
            $segment->activityDirections()->attach(collect($data['directions'])->map(fn (string $key): int => $directions[$key]->id));

            return [$slug => $segment];
        })->all();
    }

    /**
     * @param  array<string, Room>  $rooms
     * @param  array<string, ClassType>  $classTypes
     * @param  array<string, TrainerType>  $trainerTypes
     * @param  array<string, ClassPassSegment>  $segments
     * @return array<string, ClassPassPlan>
     */
    private function plans(Account $account, array $rooms, array $classTypes, array $trainerTypes, array $segments): array
    {
        return collect(DemoStudioFixture::classPassPlans())->mapWithKeys(function (array $data, string $slug) use ($account, $rooms, $classTypes, $trainerTypes, $segments): array {
            $plan = $account->classPassPlans()->create([
                'class_pass_segment_id' => $segments[$data['segment']]->id,
                'name' => $data['name'],
                'slug' => $slug,
                'schedule_kind' => $data['kind'],
                'description' => 'Умовна демонстраційна ціна, яка не є реальною пропозицією.',
                'price_cents' => $data['price'],
                'currency' => 'UAH',
                'sessions_count' => $data['sessions'],
                'validity_days' => $data['validity'],
                'total_validity_days' => $data['total_validity'],
                'allows_any_time' => false,
                'is_trial' => $data['trial'],
                'is_active' => true,
                'sort_order' => 10,
            ]);
            $plan->classTypes()->attach(collect($data['class_types'])->map(fn (string $key): int => $classTypes[$key]->id));
            $plan->trainerTypes()->attach(collect($data['trainer_types'])->map(fn (string $key): int => $trainerTypes[$key]->id));
            $plan->rooms()->attach(collect($data['rooms'])->map(fn (string $key): int => $rooms[$key]->id));

            return [$slug => $plan];
        })->all();
    }

    /** @return array<int, Customer> */
    private function customers(Account $account): array
    {
        return collect(DemoStudioFixture::customerNames())->map(function (string $name, int $index) use ($account): Customer {
            $number = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

            return $account->customers()->create([
                'name' => $name,
                'email' => "customer{$number}@ladna-demo.example.test",
                'phone' => "+380000000{$number}",
                'password' => null,
                'default_language' => 'uk',
            ]);
        })->all();
    }

    /**
     * @param  array<string, Room>  $rooms
     * @param  array<string, ClassType>  $classTypes
     * @param  array<string, Trainer>  $trainers
     */
    private function schedule(Account $account, Location $location, array $rooms, array $classTypes, array $trainers): void
    {
        $startDate = now($account->timezone)->startOfWeek()->toDateString();

        foreach (DemoStudioFixture::scheduleRows() as $row) {
            $account->scheduleSeries()->create([
                'location_id' => $location->id,
                'room_id' => $rooms[$row['room']]->id,
                'class_type_id' => $classTypes[$row['class_type']]->id,
                'trainer_id' => $trainers[$row['trainer']]->id,
                'weekday' => $row['weekday'],
                'start_time' => $row['start_time'],
                'start_date' => $startDate,
                'status' => ScheduleSeriesStatus::Active->value,
            ]);
        }

        $this->generateAccountSchedule->execute($account);
    }

    /**
     * @param  array<string, Room>  $rooms
     * @param  array<string, ClassType>  $classTypes
     * @param  array<string, Trainer>  $trainers
     * @param  array<string, ClassPassPlan>  $plans
     * @param  array<int, Customer>  $customers
     */
    private function bookingsAndPasses(Account $account, User $owner, Location $location, array $rooms, array $classTypes, array $trainers, array $plans, array $customers): void
    {
        $pastClasses = $this->pastClasses($account, $location, $rooms['lavender-hall'], $classTypes, $trainers);
        $this->manualUpcomingClasses($account, $location, $rooms['plum-studio'], $classTypes, $trainers);
        $passes = $this->customerPasses($account, $owner, $location, $plans['group-8'], $customers);
        $bookings = $this->pastBookings($account, $owner, $pastClasses, $customers);

        CustomerClassPassReservation::query()->create([
            'account_id' => $account->id,
            'customer_class_pass_id' => $passes[0]->id,
            'class_booking_id' => $bookings[0]->id,
            'scheduled_class_id' => $bookings[0]->scheduled_class_id,
            'status' => CustomerClassPassReservationStatus::Used->value,
            'reserved_at' => $bookings[0]->created_at,
            'used_at' => $bookings[0]->attended_at,
        ]);
        $passes[0]->update(['used_sessions_count' => 1, 'opened_at' => $bookings[0]->attended_at]);

        $futureClasses = $account->scheduledClasses()->where('starts_at', '>', now())->orderBy('starts_at')->take(5)->get();
        foreach ($futureClasses as $index => $scheduledClass) {
            $customer = $customers[$index + 1];
            $booking = $this->booking($account, $owner, $scheduledClass, $customer, 'booked');

            if ($index === 0) {
                CustomerClassPassReservation::query()->create([
                    'account_id' => $account->id,
                    'customer_class_pass_id' => $passes[1]->id,
                    'class_booking_id' => $booking->id,
                    'scheduled_class_id' => $scheduledClass->id,
                    'status' => CustomerClassPassReservationStatus::Reserved->value,
                    'reserved_at' => now()->subDay(),
                ]);
                $passes[1]->update(['reserved_sessions_count' => 1]);
            }
        }
    }

    /** @param array<string, ClassType> $classTypes @param array<string, Trainer> $trainers @return array<int, ScheduledClass> */
    private function pastClasses(Account $account, Location $location, Room $room, array $classTypes, array $trainers): array
    {
        $typeKeys = ['morning-yoga', 'pilates-flow', 'barre-balance', 'functional-fit'];
        $trainerKeys = array_keys($trainers);

        return collect(range(1, 10))->map(function (int $offset) use ($account, $location, $room, $classTypes, $trainers, $typeKeys, $trainerKeys): ScheduledClass {
            $classType = $classTypes[$typeKeys[($offset - 1) % count($typeKeys)]];
            $startsAt = now($account->timezone)->subDays(11 - $offset)->setTime(18, 0)->utc();

            return $account->scheduledClasses()->create([
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainers[$trainerKeys[($offset - 1) % count($trainerKeys)]]->id,
                'title' => $classType->name,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($classType->default_duration_minutes),
                'capacity' => $classType->default_capacity,
                'is_generated' => false,
                'is_public' => true,
                'status' => 'scheduled',
            ]);
        })->all();
    }

    /** @param array<string, ClassType> $classTypes @param array<string, Trainer> $trainers */
    private function manualUpcomingClasses(Account $account, Location $location, Room $room, array $classTypes, array $trainers): void
    {
        foreach ([['personal-session', 'mariia', 2], ['studio-rental', 'sofiia', 4]] as [$typeKey, $trainerKey, $days]) {
            $classType = $classTypes[$typeKey];
            $startsAt = now($account->timezone)->addDays($days)->setTime(14, 0)->utc();
            $account->scheduledClasses()->create([
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainers[$trainerKey]->id,
                'title' => $classType->name,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes($classType->default_duration_minutes),
                'capacity' => $classType->default_capacity,
                'is_generated' => false,
                'is_public' => false,
                'status' => 'scheduled',
            ]);
        }
    }

    /** @param array<int, Customer> $customers @return array<int, CustomerClassPass> */
    private function customerPasses(Account $account, User $owner, Location $location, ClassPassPlan $plan, array $customers): array
    {
        return collect(DemoStudioFixture::passStatuses())->map(function (CustomerClassPassStatus $status, int $index) use ($account, $owner, $location, $plan, $customers): CustomerClassPass {
            $purchasedAt = now()->subDays(35 - ($index * 4));
            $isActive = in_array($status, [CustomerClassPassStatus::Active, CustomerClassPassStatus::Freezed], true);

            return $account->customerClassPasses()->create([
                'customer_id' => $customers[$index]->id,
                'class_pass_plan_id' => $plan->id,
                'code' => 'DEMO-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'source' => 'manual',
                'issued_location_id' => $location->id,
                'is_paid' => true,
                'issued_by_actor_user_id' => $owner->id,
                'issued_by_actor_name' => $owner->name,
                'issued_by_actor_email' => $owner->email,
                'issued_by_actor_role' => AccountRole::Owner->value,
                'status' => $status->value,
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'price_cents' => $plan->price_cents,
                'paid_amount_cents' => $plan->price_cents,
                'currency' => 'UAH',
                'sessions_count' => $plan->sessions_count,
                'validity_days' => $plan->validity_days,
                'total_validity_days' => $plan->total_validity_days,
                'allows_any_time' => false,
                'reserved_sessions_count' => 0,
                'used_sessions_count' => $status === CustomerClassPassStatus::UsedUp ? $plan->sessions_count : 0,
                'purchased_at' => $purchasedAt,
                'opened_at' => $status === CustomerClassPassStatus::UsedUp ? $purchasedAt->copy()->addDay() : null,
                'usable_until_at' => $purchasedAt->copy()->addDays($plan->total_validity_days),
                'closed_at' => $isActive ? null : now()->subDays(2),
                'frozen_at' => $status === CustomerClassPassStatus::Freezed ? now()->subDays(3) : null,
                'is_active' => $isActive,
            ]);
        })->all();
    }

    /** @param array<int, ScheduledClass> $classes @param array<int, Customer> $customers @return array<int, ClassBooking> */
    private function pastBookings(Account $account, User $owner, array $classes, array $customers): array
    {
        $statuses = DemoStudioFixture::bookingStatuses();

        return collect($classes)->map(function (ScheduledClass $class, int $index) use ($account, $owner, $customers, $statuses): ClassBooking {
            $status = $statuses[$index % count($statuses)];
            $booking = $this->booking($account, $owner, $class, $customers[$index], $status->value);

            if ($status->value === 'attended') {
                $booking->update(['attended_at' => $class->ends_at]);
            }

            return $booking;
        })->all();
    }

    private function booking(Account $account, User $owner, ScheduledClass $class, Customer $customer, string $status): ClassBooking
    {
        return $account->classBookings()->create([
            'scheduled_class_id' => $class->id,
            'customer_id' => $customer->id,
            'booked_by_user_id' => $owner->id,
            'booked_by_actor_user_id' => $owner->id,
            'booked_by_actor_name' => $owner->name,
            'booked_by_actor_email' => $owner->email,
            'booked_by_actor_role' => AccountRole::Owner->value,
            'status' => $status,
            'skip_class_pass_reservation' => true,
        ]);
    }

    /**
     * @param  array<string, Room>  $rooms
     * @param  array<int, Customer>  $customers
     */
    private function peopleCounter(Account $account, User $owner, Location $location, array $rooms, array $customers): void
    {
        $classes = $account->scheduledClasses()
            ->with('classType')
            ->where('starts_at', '<', now())
            ->latest('starts_at')
            ->take(6)
            ->get();

        foreach ($classes as $index => $scheduledClass) {
            $usedCustomerIds = $scheduledClass->classBookings()->pluck('customer_id');
            $additionalCustomers = collect($customers)
                ->reject(fn (Customer $customer): bool => $usedCustomerIds->contains($customer->id))
                ->skip($index * 2)
                ->take(4 + ($index % 3));

            foreach ($additionalCustomers->values() as $bookingIndex => $customer) {
                $status = $bookingIndex % 4 === 3
                    ? ClassBookingStatus::NoShow
                    : ClassBookingStatus::Attended;
                $booking = $this->booking($account, $owner, $scheduledClass, $customer, $status->value);

                if ($status === ClassBookingStatus::Attended) {
                    $booking->update(['attended_at' => $scheduledClass->ends_at]);
                }
            }

            $assignedCount = $scheduledClass->classBookings()
                ->notCorrectedRemoved()
                ->whereIn('status', [
                    ClassBookingStatus::Booked->value,
                    ClassBookingStatus::Attended->value,
                    ClassBookingStatus::NoShow->value,
                ])
                ->count();
            $attendedCount = $scheduledClass->classBookings()
                ->notCorrectedRemoved()
                ->where('status', ClassBookingStatus::Attended->value)
                ->count();
            $trainerAdjustment = $scheduledClass->peopleCounterTrainerAdjustment();
            $visibleDetectedCount = $assignedCount + ($index % 3 === 0 ? 2 : 0);
            $detectedCount = $visibleDetectedCount + $trainerAdjustment;
            $sampleCounts = [max(0, $detectedCount - 1), $detectedCount];
            $sampleTimes = [
                $scheduledClass->starts_at->copy()->addMinutes(20),
                $scheduledClass->starts_at->copy()->addMinutes(40),
            ];

            foreach ($sampleCounts as $sampleIndex => $sampleCount) {
                $this->createPeopleCounterSample(
                    $account,
                    $location,
                    $rooms['lavender-hall'],
                    $sampleCount,
                    $sampleTimes[$sampleIndex],
                    $scheduledClass,
                );
            }

            $expectedPeopleCount = $attendedCount + $trainerAdjustment;
            $delta = $detectedCount - $expectedPeopleCount;

            $scheduledClass->peopleCount()->create([
                'account_id' => $account->id,
                'location_id' => $location->id,
                'room_id' => $scheduledClass->room_id,
                'trainer_id' => $scheduledClass->trainer_id,
                'status' => $delta === 0
                    ? ScheduledClassPeopleCount::StatusMatched
                    : ScheduledClassPeopleCount::StatusMismatch,
                'attended_count' => $attendedCount,
                'detected_count' => $detectedCount,
                'delta' => $delta,
                'successful_samples_count' => count($sampleCounts),
                'failed_samples_count' => 0,
                'first_sampled_at' => $sampleTimes[0],
                'last_sampled_at' => $sampleTimes[1],
                'summarized_at' => $scheduledClass->ends_at->copy()->addMinutes(15),
            ]);
        }

        foreach ([
            'lavender-hall' => ['count' => 6, 'minutes_ago' => 7],
            'plum-studio' => ['count' => 3, 'minutes_ago' => 11],
        ] as $roomSlug => $snapshot) {
            $this->createPeopleCounterSample(
                $account,
                $location,
                $rooms[$roomSlug],
                $snapshot['count'],
                now()->subMinutes($snapshot['minutes_ago']),
            );
        }
    }

    private function createPeopleCounterSample(
        Account $account,
        Location $location,
        Room $room,
        int $detectedCount,
        CarbonInterface $capturedAt,
        ?ScheduledClass $scheduledClass = null,
    ): PeopleCounterSample {
        $detections = $detectedCount === 0
            ? []
            : collect(range(1, $detectedCount))->map(fn (int $index): array => [
                'label' => 'person',
                'confidence' => round(0.91 + (($index % 5) * 0.01), 2),
                'box' => [
                    'x' => 90 + (($index * 137) % 950),
                    'y' => 80 + (($index * 83) % 480),
                    'width' => 72,
                    'height' => 168,
                ],
            ])->all();

        return PeopleCounterSample::query()->create([
            'account_id' => $account->id,
            'scheduled_class_id' => $scheduledClass?->id,
            'location_id' => $location->id,
            'room_id' => $room->id,
            'captured_at' => $capturedAt,
            'status' => PeopleCounterSample::StatusSucceeded,
            'failure_reason' => null,
            'original_image_path' => DemoStudioFixture::cameraImagePath($room->slug),
            'masked_image_path' => null,
            'image_width' => 1280,
            'image_height' => 720,
            'detected_count' => $detectedCount,
            'average_confidence' => '0.9300',
            'detections' => $detections,
            'response_payload' => [
                'source' => 'synthetic_demo_fixture',
                'count' => $detectedCount,
                'detections' => $detections,
            ],
        ]);
    }

    private function leads(Account $account): void
    {
        foreach (DemoStudioFixture::leads() as $index => $lead) {
            WebsiteLead::query()->create([
                'account_id' => $account->id,
                'name' => $lead['name'],
                'phone' => $lead['phone'],
                'source_page' => 'https://demo.example.test/campaign-'.($index + 1),
                'status' => $lead['status']->value,
                'notes' => 'Синтетична заявка для демонстрації.',
            ]);
        }
    }

    /** @param array<string, ClassPassPlan> $plans @param array<int, Customer> $customers */
    private function cashflow(Account $account, User $owner, Location $location, array $plans, array $customers): void
    {
        $this->manualPurchases($account, $owner, $location, $plans['group-8'], $customers);
        $categories = collect(['Оренда', 'Комунальні послуги', 'Інвентар'])->map(
            fn (string $name): ExpenseCategory => $account->expenseCategories()->create(['name' => $name, 'is_active' => true]),
        );

        foreach ([85000, 120000, 64500] as $index => $amount) {
            $expense = $account->studioExpenses()->create([
                'expense_category_id' => $categories[$index]->id,
                'location_id' => $location->id,
                'amount_cents' => $amount,
                'currency' => 'UAH',
                'payment_method' => StudioExpense::PaymentMethodCashdesk,
                'occurred_at' => now()->subDays(12 - ($index * 3)),
                'actor_user_id' => $owner->id,
                'actor_name' => $owner->name,
                'actor_email' => $owner->email,
                'actor_role' => AccountRole::Owner->value,
                'reason' => 'Синтетична операційна витрата.',
            ]);
            $account->studioCashEntries()->create([
                'location_id' => $location->id,
                'studio_expense_id' => $expense->id,
                'direction' => StudioCashEntry::DirectionOut,
                'purpose' => StudioCashEntry::PurposeOperationalExpense,
                'amount_cents' => $amount,
                'currency' => 'UAH',
                'occurred_at' => $expense->occurred_at,
                'actor_user_id' => $owner->id,
                'actor_name' => $owner->name,
                'actor_email' => $owner->email,
                'actor_role' => AccountRole::Owner->value,
                'reason' => $expense->reason,
            ]);
        }

        $voided = $account->studioExpenses()->latest('id')->firstOrFail();
        $voided->update([
            'voided_at' => now()->subDay(),
            'void_reason' => 'Синтетичне скасування дубльованої витрати.',
            'voided_by_actor_user_id' => $owner->id,
            'voided_by_actor_name' => $owner->name,
            'voided_by_actor_email' => $owner->email,
            'voided_by_actor_role' => AccountRole::Owner->value,
        ]);
        $account->studioCashEntries()->create([
            'location_id' => $location->id,
            'studio_expense_id' => $voided->id,
            'direction' => StudioCashEntry::DirectionIn,
            'purpose' => StudioCashEntry::PurposeExpenseReversal,
            'amount_cents' => $voided->amount_cents,
            'currency' => 'UAH',
            'occurred_at' => $voided->voided_at,
            'actor_user_id' => $owner->id,
            'actor_name' => $owner->name,
            'actor_email' => $owner->email,
            'actor_role' => AccountRole::Owner->value,
            'reason' => $voided->void_reason,
        ]);
    }

    /** @param array<int, Customer> $customers */
    private function manualPurchases(Account $account, User $owner, Location $location, ClassPassPlan $plan, array $customers): void
    {
        foreach ([140000, 240000, 240000, 110000] as $index => $amount) {
            $paidAt = now()->subDays(24 - ($index * 5));
            $purchase = $account->customerPurchases()->create([
                'customer_id' => $customers[$index]->id,
                'location_id' => $location->id,
                'class_pass_plan_id' => $plan->id,
                'provider' => CustomerPurchase::ProviderStudioCash,
                'payment_source' => CustomerPurchase::SourceManualCashClassPass,
                'order_id' => 'demo-cash-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'schedule_kind' => $plan->schedule_kind->value,
                'amount_cents' => $amount,
                'currency' => 'UAH',
                'sessions_count' => $plan->sessions_count,
                'validity_days' => $plan->validity_days,
                'total_validity_days' => $plan->total_validity_days,
                'started_at' => $paidAt,
                'paid_at' => $paidAt,
            ]);
            $account->studioCashEntries()->create([
                'location_id' => $location->id,
                'direction' => StudioCashEntry::DirectionIn,
                'purpose' => StudioCashEntry::PurposeDeposit,
                'amount_cents' => $amount,
                'currency' => 'UAH',
                'occurred_at' => $paidAt,
                'actor_user_id' => $owner->id,
                'actor_name' => $owner->name,
                'actor_email' => $owner->email,
                'actor_role' => AccountRole::Owner->value,
                'reason' => 'Синтетична оплата абонемента.',
            ]);

            if ($index === 0) {
                CustomerPurchaseCorrection::query()->create([
                    'account_id' => $account->id,
                    'customer_purchase_id' => $purchase->id,
                    'previous_location_id' => $location->id,
                    'new_location_id' => $location->id,
                    'previous_amount_cents' => 145000,
                    'new_amount_cents' => $amount,
                    'previous_paid_at' => $paidAt->copy()->subHour(),
                    'new_paid_at' => $paidAt,
                    'actor_user_id' => $owner->id,
                    'actor_name' => $owner->name,
                    'actor_email' => $owner->email,
                    'actor_role' => AccountRole::Owner->value,
                    'reason' => 'Синтетичне виправлення суми.',
                ]);
            }
        }
    }
}
