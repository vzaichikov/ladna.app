<?php

namespace Database\Seeders;

use App\Enums\AccountRole;
use App\Enums\ScheduleSeriesStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduleSeries;
use App\Models\SubscriptionPlan;
use App\Models\Trainer;
use App\Models\TrainerType;
use App\Models\User;
use App\Support\CharmpoleDemoCatalog;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->ensureCanSeedDemo();

        $credentials = $this->demoCredentials();

        $this->clearDemoData();

        User::create([
            'name' => $credentials['platform']['name'],
            'email' => $credentials['platform']['email'],
            'password' => Hash::make($credentials['platform']['password']),
            'system_role' => SystemRole::PlatformAdmin->value,
            'email_verified_at' => now(),
        ]);

        $owner = User::create([
            'name' => $credentials['owner']['name'],
            'email' => $credentials['owner']['email'],
            'password' => Hash::make($credentials['owner']['password']),
            'email_verified_at' => now(),
        ]);

        $account = Account::create(CharmpoleDemoCatalog::account());

        $account->users()->attach($owner->id, [
            'role' => AccountRole::Owner->value,
            'permissions' => null,
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Ladna Studio',
            'slug' => 'ladna-studio',
            'description' => 'Підписка для студії з розкладом, клієнтами та заняттями.',
            'price_cents' => 490000,
            'currency' => 'UAH',
            'billing_interval' => 'monthly',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $account->subscription()->create([
            'subscription_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active->value,
            'started_at' => now(),
        ]);

        $location = Location::create([
            'account_id' => $account->id,
            ...CharmpoleDemoCatalog::location(),
        ]);

        $rooms = $this->rooms($account, $location);
        $directions = $this->directions($account);
        $classTypes = $this->classTypes($account, $directions);
        $trainerTypes = $this->trainerTypes($account);

        $this->call(ClassPassPlanSeeder::class);

        $trainers = $this->trainers($account, $trainerTypes);

        $this->schedule($account, $location, $rooms, $classTypes, $trainers);
    }

    private function ensureCanSeedDemo(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DatabaseSeeder is disabled in production.');
        }
    }

    /**
     * @return array{platform: array{name: string, email: string, password: string}, owner: array{name: string, email: string, password: string}}
     */
    private function demoCredentials(): array
    {
        $credentials = config('demo.users');

        if (! is_array($credentials)) {
            throw new RuntimeException('Demo user credentials are not configured.');
        }

        foreach (['platform', 'owner'] as $key) {
            $user = $credentials[$key] ?? [];

            if (! is_array($user)) {
                throw new RuntimeException("Demo {$key} user credentials are not configured.");
            }

            foreach (['name', 'email', 'password'] as $field) {
                if (! is_string($user[$field] ?? null) || blank($user[$field])) {
                    throw new RuntimeException("Demo {$key} {$field} must be configured in the local environment.");
                }
            }

            if (! filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("Demo {$key} email must be a valid email address.");
            }
        }

        return $credentials;
    }

    private function clearDemoData(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ([
                'scheduled_class_cancellation_effects',
                'scheduled_class_cancellations',
                'customer_class_pass_adjustments',
                'customer_class_pass_reservations',
                'customer_class_passes',
                'customer_purchases',
                'website_leads',
                'class_bookings',
                'scheduled_classes',
                'schedule_series',
                'class_pass_plan_room',
                'class_pass_plan_class_type',
                'class_pass_plan_trainer_type',
                'class_pass_plan_activity_direction',
                'class_pass_plans',
                'activity_direction_class_pass_segment',
                'class_pass_segments',
                'account_api_tokens',
                'integration_settings',
                'customer_remember_tokens',
                'customer_otp_challenges',
                'customer_auth_settings',
                'customers',
                'rooms',
                'locations',
                'class_types',
                'activity_directions',
                'trainers',
                'trainer_types',
                'account_subscriptions',
                'account_memberships',
                'accounts',
                'subscription_plans',
                'users',
            ] as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @return array<string, Room>
     */
    private function rooms(Account $account, Location $location): array
    {
        return collect(CharmpoleDemoCatalog::rooms())
            ->mapWithKeys(fn (array $room, string $slug): array => [$slug => Room::create([
                'account_id' => $account->id,
                'location_id' => $location->id,
                'slug' => $slug,
                ...$room,
            ])])
            ->all();
    }

    /**
     * @return array<string, ActivityDirection>
     */
    private function directions(Account $account): array
    {
        return collect(CharmpoleDemoCatalog::directions())
            ->mapWithKeys(fn (array $direction, string $slug): array => [$slug => ActivityDirection::create([
                'account_id' => $account->id,
                'slug' => $slug,
                ...$direction,
            ])])
            ->all();
    }

    /**
     * @param  array<string, ActivityDirection>  $directions
     * @return array<string, ClassType>
     */
    private function classTypes(Account $account, array $directions): array
    {
        return collect(CharmpoleDemoCatalog::classTypes())->mapWithKeys(function (array $classType, string $slug) use ($account, $directions): array {
            $directionSlug = $classType['direction_slug'];

            return [$slug => ClassType::create([
                'account_id' => $account->id,
                'activity_direction_id' => is_string($directionSlug) ? $directions[$directionSlug]->id : null,
                'slug' => $slug,
                ...collect($classType)->except('direction_slug')->all(),
            ])];
        })->all();
    }

    /**
     * @return array<string, TrainerType>
     */
    private function trainerTypes(Account $account): array
    {
        return collect(CharmpoleDemoCatalog::trainerTypes())
            ->mapWithKeys(fn (array $trainerType, string $key): array => [$key => TrainerType::create([
                'account_id' => $account->id,
                ...$trainerType,
            ])])
            ->all();
    }

    /**
     * @param  array<string, TrainerType>  $trainerTypes
     * @return array<string, Trainer>
     */
    private function trainers(Account $account, array $trainerTypes): array
    {
        return collect(CharmpoleDemoCatalog::trainers())
            ->mapWithKeys(fn (array $trainer, string $name): array => [$name => Trainer::create([
                'account_id' => $account->id,
                'trainer_type_id' => $trainerTypes[$trainer['trainer_type_key']]->id,
                'email' => null,
                'phone' => null,
                ...collect($trainer)->except('trainer_type_key')->all(),
            ])])
            ->all();
    }

    /**
     * @param  array<string, Room>  $rooms
     * @param  array<string, ClassType>  $classTypes
     * @param  array<string, Trainer>  $trainers
     */
    private function schedule(Account $account, Location $location, array $rooms, array $classTypes, array $trainers): void
    {
        $startDate = Carbon::now('Europe/Kyiv')->startOfWeek()->toDateString();

        foreach (CharmpoleDemoCatalog::scheduleRows() as $row) {
            ScheduleSeries::create([
                'account_id' => $account->id,
                'location_id' => $location->id,
                'room_id' => $rooms[$row['room_slug']]->id,
                'class_type_id' => $classTypes[$row['class_type_slug']]->id,
                'trainer_id' => $trainers[$row['trainer_name']]->id,
                'title' => null,
                'description' => null,
                'weekday' => $row['weekday'],
                'start_time' => $row['start_time'],
                'start_date' => $startDate,
                'end_date' => null,
                'status' => ScheduleSeriesStatus::Active->value,
            ]);
        }
    }
}
