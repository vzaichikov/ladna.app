<?php

namespace Database\Seeders;

use App\Actions\GenerateScheduleOccurrences;
use App\Enums\AccountRole;
use App\Enums\ScheduleKind;
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

        $bigHall = Room::create([
            'account_id' => $account->id,
            'location_id' => $location->id,
            'name' => 'Великий зал',
            'slug' => 'big-hall',
            'capacity' => 12,
            'is_active' => true,
        ]);

        Room::create([
            'account_id' => $account->id,
            'location_id' => $location->id,
            'name' => 'Малий зал',
            'slug' => 'small-hall',
            'capacity' => 6,
            'is_active' => true,
        ]);

        $directions = $this->directions($account);
        $classTypes = $this->classTypes($account, $directions);
        $trainerTypes = $this->trainerTypes($account);
        $this->call(ClassPassPlanSeeder::class);
        $trainers = $this->trainers($account, $trainerTypes);

        $this->schedule($account, $location, $bigHall, $classTypes, $trainers);

        unset($platformUser);
    }

    private function clearDemoData(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ([
            'class_bookings',
            'scheduled_classes',
            'schedule_series',
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
     * @return array<string, ActivityDirection>
     */
    private function directions(Account $account): array
    {
        return collect([
            ['Pole Dance', 'Техніка, сила та танцювальні комбінації на пілоні.', '#c7f000'],
            ['Exotic', 'Exotic pole, флоу, музикальність та хореографія.', '#ff008c'],
            ['Stretching', 'Гнучкість, мобільність та відновлення.', '#ff2b2b'],
            ['Kids', 'Дитячі групи Pole Kids.', '#ffffff'],
            ['Acro', 'Акробатика та трюкова підготовка.', '#ffffff'],
        ])->mapWithKeys(function (array $direction) use ($account): array {
            [$name, $description, $color] = $direction;

            return [$name => ActivityDirection::create([
                'account_id' => $account->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $description,
                'color' => $color,
                'is_active' => true,
            ])];
        })->all();
    }

    /**
     * @param  array<string, ActivityDirection>  $directions
     * @return array<string, ClassType>
     */
    private function classTypes(Account $account, array $directions): array
    {
        return collect([
            ['Pole Dance', 'Pole Dance', '#c7f000', 60, 12],
            ['Pole Kids', 'Kids', '#ffffff', 60, 8],
            ['Exot Easy', 'Exotic', '#c7f000', 60, 10],
            ['Exot', 'Exotic', '#ff008c', 60, 10],
            ['Exot Middle', 'Exotic', '#ffad00', 60, 10],
            ['Stretching', 'Stretching', '#ff2b2b', 60, 12],
            ['Tricks', 'Acro', '#ff008c', 60, 10],
            ['Acro class*', 'Acro', '#ffffff', 60, 12],
        ])->mapWithKeys(function (array $classType) use ($account, $directions): array {
            [$name, $direction, $color, $duration, $capacity] = $classType;

            return [$name => ClassType::create([
                'account_id' => $account->id,
                'activity_direction_id' => $directions[$direction]->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => null,
                'color' => $color,
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'default_duration_minutes' => $duration,
                'booking_cutoff_minutes' => 60,
                'default_capacity' => $capacity,
                'is_active' => true,
            ])];
        })->all();
    }

    /**
     * @return array<string, TrainerType>
     */
    private function trainerTypes(Account $account): array
    {
        return [
            'trainer' => $account->trainerTypes()->create([
                'name' => 'Trainer',
                'icon' => 'user-round',
                'color' => '#3B223F',
                'is_default' => true,
                'sort_order' => 10,
            ]),
            'top' => $account->trainerTypes()->create([
                'name' => 'TOP-trainer',
                'icon' => 'crown',
                'color' => '#D80A7D',
                'is_default' => false,
                'sort_order' => 20,
            ]),
        ];
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

    /**
     * @param  array<string, ClassType>  $classTypes
     * @param  array<string, Trainer>  $trainers
     */
    private function schedule(Account $account, Location $location, Room $room, array $classTypes, array $trainers): void
    {
        $rows = [
            [1, '09:00', 'Exot Easy', 'Настя'],
            [1, '10:00', 'Pole Dance', 'Настя'],
            [1, '11:00', 'Stretching', 'Настя'],
            [1, '16:00', 'Pole Dance', 'Настя'],
            [1, '17:00', 'Exot Easy', 'Настя'],
            [1, '18:00', 'Pole Dance', 'Катя'],
            [1, '19:00', 'Exot Middle', 'Катя'],
            [1, '20:00', 'Pole Dance', 'Катя'],
            [2, '09:00', 'Tricks', 'Slastya'],
            [2, '10:00', 'Exot', 'Slastya'],
            [2, '16:00', 'Pole Kids', 'Ліза'],
            [2, '17:00', 'Pole Kids', 'Ліза'],
            [2, '18:00', 'Pole Dance', 'Ліза'],
            [2, '19:00', 'Pole Dance', 'Аліна'],
            [2, '20:00', 'Exot Easy', 'Аліна'],
            [3, '09:00', 'Exot Easy', 'Настя'],
            [3, '10:00', 'Pole Dance', 'Настя'],
            [3, '11:00', 'Stretching', 'Настя'],
            [3, '16:00', 'Pole Dance', 'Настя'],
            [3, '17:00', 'Exot Easy', 'Настя'],
            [3, '18:00', 'Pole Dance', 'Катя'],
            [3, '19:00', 'Exot Middle', 'Катя'],
            [3, '20:00', 'Pole Dance', 'Катя'],
            [4, '09:00', 'Stretching', 'Slastya'],
            [4, '10:00', 'Exot', 'Slastya'],
            [4, '16:00', 'Pole Kids', 'Ліза'],
            [4, '17:00', 'Stretching', 'Женя'],
            [4, '18:00', 'Pole Dance', 'Женя'],
            [4, '19:00', 'Pole Dance', 'Женя'],
            [4, '20:00', 'Exot Easy', 'Аліна'],
            [5, '18:00', 'Pole Dance', 'Катя'],
            [5, '19:00', 'Exot Middle', 'Катя'],
            [6, '09:00', 'Acro class*', '_loco_man'],
            [6, '11:00', 'Exot Easy', 'Настя'],
            [6, '12:00', 'Stretching', 'Настя'],
            [6, '13:00', 'Pole Dance', 'Настя'],
            [7, '10:00', 'Pole Dance', 'Женя'],
            [7, '11:00', 'Pole Dance', 'Женя'],
            [7, '12:00', 'Stretching', 'Женя'],
            [7, '13:00', 'Exot Easy', 'Аліна'],
        ];

        $generator = app(GenerateScheduleOccurrences::class);
        $startDate = Carbon::now('Europe/Kyiv')->startOfWeek()->toDateString();

        foreach ($rows as $row) {
            [$weekday, $time, $classType, $trainer] = $row;

            $series = ScheduleSeries::create([
                'account_id' => $account->id,
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classTypes[$classType]->id,
                'trainer_id' => $trainers[$trainer]->id,
                'title' => null,
                'description' => null,
                'weekday' => $weekday,
                'start_time' => $time,
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
}
