<?php

namespace Database\Seeders;

use App\Actions\GenerateScheduleOccurrences;
use App\Enums\AccountRole;
use App\Enums\ScheduleSeriesStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->clearDemoData();

        $platformUser = User::create([
            'name' => 'Platform Admin',
            'email' => 'platform@example.com',
            'password' => Hash::make('password'),
            'system_role' => SystemRole::PlatformAdmin->value,
            'email_verified_at' => now(),
        ]);

        $owner = User::create([
            'name' => 'Настя',
            'email' => 'nastya@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $account = Account::create([
            'name' => 'Charmpole',
            'slug' => 'charmpole',
            'status' => 'active',
            'default_language' => 'uk',
            'default_currency' => 'UAH',
            'logo_path' => 'brand/charmpole-icon.svg',
            'brand_color' => '#d80a7d',
            'timezone' => 'Europe/Kyiv',
            'enabled_schedule_kinds' => CharmpoleDemoCatalog::enabledScheduleKinds(),
            'schedule_kind_colors' => CharmpoleDemoCatalog::scheduleKindColors(),
        ]);

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
            'name' => 'Charmpole',
            'slug' => 'charmpole',
            'address' => 'Київ, проспект Берестейський (Перемоги), 56',
            'phone' => '+380939470278',
            'email' => 'hello@charmpole.dance',
            'timezone' => 'Europe/Kyiv',
            'is_active' => true,
        ]);

        $rooms = $this->rooms($account, $location);

        $directions = $this->directions($account);
        $classTypes = $this->classTypes($account, $directions);
        $trainerTypes = $this->trainerTypes($account);
        $this->call(ClassPassPlanSeeder::class);
        $trainers = $this->trainers($account, $trainerTypes);
        $this->customers($account);

        $this->schedule($account, $location, $rooms['big-hall'], $classTypes, $trainers);
        $this->customerClassPasses($account);

        unset($platformUser);
    }

    private function clearDemoData(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ([
            'customer_class_pass_reservations',
            'customer_class_passes',
            'class_bookings',
            'scheduled_classes',
            'schedule_series',
            'class_pass_plan_room',
            'class_pass_plan_class_type',
            'class_pass_plan_trainer_type',
            'class_pass_plan_activity_direction',
            'class_pass_plans',
            'rooms',
            'locations',
            'class_types',
            'activity_directions',
            'trainers',
            'trainer_types',
            'account_subscriptions',
            'account_memberships',
            'customers',
            'accounts',
            'subscription_plans',
            'users',
        ] as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        Storage::disk('public')->deleteDirectory('trainer-photos/charmpole');
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
                'name' => $room['name'],
                'slug' => $slug,
                'capacity' => $room['capacity'],
                'is_active' => $room['is_active'],
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
                'name' => $direction['name'],
                'slug' => $slug,
                'description' => $direction['description'],
                'color' => $direction['color'],
                'is_active' => $direction['is_active'],
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
                'name' => $classType['name'],
                'slug' => $slug,
                'description' => $classType['description'],
                'color' => $classType['color'],
                'schedule_kind' => $classType['schedule_kind'],
                'default_duration_minutes' => $classType['default_duration_minutes'],
                'booking_cutoff_minutes' => $classType['booking_cutoff_minutes'],
                'default_capacity' => $classType['default_capacity'],
                'is_active' => $classType['is_active'],
            ])];
        })->all();
    }

    /**
     * @return array<string, TrainerType>
     */
    private function trainerTypes(Account $account): array
    {
        return collect(CharmpoleDemoCatalog::trainerTypes())
            ->mapWithKeys(fn (array $trainerType, string $key): array => [$key => $account->trainerTypes()->create($trainerType)])
            ->all();
    }

    /**
     * @param  array<string, TrainerType>  $trainerTypes
     * @return array<string, Trainer>
     */
    private function trainers(Account $account, array $trainerTypes): array
    {
        $avatars = [
            'Настя' => 'avatar-nastya.png',
            'Slastya' => 'avatar-slastya.png',
            'Катя' => 'avatar-katya.png',
            'Ліза' => 'avatar-liza.png',
            'Женя' => 'avatar-jenya.png',
            'Аліна' => 'avatar-alina.png',
            '_loco_man' => 'avatar-loco-man.png',
        ];

        return collect($avatars)->mapWithKeys(function (string $avatar, string $name) use ($account, $trainerTypes): array {
            return [$name => Trainer::create([
                'account_id' => $account->id,
                'trainer_type_id' => $name === 'Настя' ? $trainerTypes['top']->id : $trainerTypes['trainer']->id,
                'name' => $name,
                'slug' => Str::slug($name) ?: Str::slug(str_replace('_', ' ', $name)),
                'email' => null,
                'phone' => null,
                'bio' => 'Тренер студії Charmpole.',
                'photo_path' => $this->storeAvatar($avatar),
                'is_active' => true,
            ])];
        })->all();
    }

    private function storeAvatar(string $filename): ?string
    {
        $path = 'trainer-photos/charmpole/'.$filename;
        $response = Http::timeout(10)->get('https://charmpole.dance/assets/img/'.$filename);

        if (! $response->successful()) {
            return null;
        }

        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    private function customers(Account $account): void
    {
        collect(CharmpoleDemoCatalog::customers())->each(function (array $customer) use ($account): void {
            Customer::create([
                'account_id' => $account->id,
                'name' => $customer['name'],
                'phone' => $customer['phone'],
                'email' => $customer['email'],
                'password' => Hash::make('password'),
                'default_language' => $account->default_language,
                'email_verified_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<string, ClassType>  $classTypes
     * @param  array<string, Trainer>  $trainers
     */
    private function schedule(Account $account, Location $location, Room $room, array $classTypes, array $trainers): void
    {
        $generator = app(GenerateScheduleOccurrences::class);
        $startDate = Carbon::now('Europe/Kyiv')->startOfWeek()->toDateString();

        foreach (CharmpoleDemoCatalog::scheduleRows() as $row) {
            $series = ScheduleSeries::create([
                'account_id' => $account->id,
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classTypes[$row['class_type_slug']]->id,
                'trainer_id' => $trainers[$row['trainer_name']]->id,
                'title' => null,
                'description' => null,
                'weekday' => $row['weekday'],
                'start_time' => $row['start_time'],
                'start_date' => $startDate,
                'end_date' => null,
                'capacity' => null,
                'duration_minutes' => null,
                'booking_cutoff_minutes' => null,
                'status' => ScheduleSeriesStatus::Active->value,
            ]);

            $generator->execute($series);
        }
    }

    private function customerClassPasses(Account $account): void
    {
        foreach (CharmpoleDemoCatalog::customerClassPasses() as $pass) {
            $customer = $account->customers()->where('email', $pass['customer_email'])->first();
            $classPassPlan = $account->classPassPlans()->where('slug', $pass['plan_slug'])->first();

            if (! $customer || ! $classPassPlan) {
                continue;
            }

            $purchasedAt = now()->subDays(2);

            CustomerClassPass::updateOrCreate(
                ['code' => $pass['code']],
                [
                    'account_id' => $account->id,
                    'customer_id' => $customer->id,
                    'class_pass_plan_id' => $classPassPlan->id,
                    'source' => 'manual',
                    'status' => 'active',
                    'plan_name' => $classPassPlan->name,
                    'plan_slug' => $classPassPlan->slug,
                    'price_cents' => $classPassPlan->price_cents,
                    'currency' => $classPassPlan->currency,
                    'sessions_count' => $classPassPlan->sessions_count,
                    'validity_days' => $classPassPlan->validity_days,
                    'total_validity_days' => $classPassPlan->total_validity_days,
                    'reserved_sessions_count' => 0,
                    'used_sessions_count' => 0,
                    'purchased_at' => $purchasedAt,
                    'opened_at' => null,
                    'expires_at' => null,
                    'usable_until_at' => $purchasedAt->copy()->addDays($classPassPlan->total_validity_days),
                    'closed_at' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
