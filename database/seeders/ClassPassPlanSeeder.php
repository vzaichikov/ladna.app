<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ClassPassPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ClassPassPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $account = Account::where('slug', 'charmpole')->first();

        if (! $account) {
            $this->command?->warn('Class pass demo data skipped: charmpole account was not found.');

            return;
        }

        $directionIds = collect([
            ['Pole Dance', 'Техніка, сила та танцювальні комбінації на пілоні.', '#c7f000'],
            ['Exotic', 'Exotic pole, флоу, музикальність та хореографія.', '#ff008c'],
        ])->map(function (array $direction) use ($account): int {
            [$name, $description, $color] = $direction;

            return $account->activityDirections()->firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => $description,
                    'color' => $color,
                    'is_active' => true,
                ],
            )->id;
        })->all();

        $trainerTypeIds = collect([
            ['Trainer', 'user-round', '#3B223F', true, 10],
            ['TOP-trainer', 'crown', '#D80A7D', false, 20],
        ])->map(function (array $trainerType) use ($account): int {
            [$name, $icon, $color, $isDefault, $sortOrder] = $trainerType;

            return $account->trainerTypes()->firstOrCreate(
                ['name' => $name],
                [
                    'icon' => $icon,
                    'color' => $color,
                    'is_default' => $isDefault,
                    'sort_order' => $sortOrder,
                ],
            )->id;
        })->all();

        foreach ($this->plans() as $plan) {
            $classPassPlan = ClassPassPlan::updateOrCreate(
                [
                    'account_id' => $account->id,
                    'slug' => $plan['slug'],
                ],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'price_cents' => $plan['price_cents'],
                    'currency' => $account->default_currency,
                    'sessions_count' => $plan['sessions_count'],
                    'validity_days' => 30,
                    'available_from_time' => null,
                    'available_until_time' => $plan['available_until_time'],
                    'is_active' => true,
                    'sort_order' => $plan['sort_order'],
                ],
            );

            $classPassPlan->activityDirections()->sync($directionIds);
            $classPassPlan->trainerTypes()->sync($trainerTypeIds);
        }
    }

    /**
     * @return array<int, array{name: string, slug: string, description: string, price_cents: int, sessions_count: int, available_until_time: ?string, sort_order: int}>
     */
    private function plans(): array
    {
        return [
            [
                'name' => 'START повний день',
                'slug' => 'full-day-start',
                'description' => 'Повний абонемент на 4 заняття.',
                'price_cents' => 150000,
                'sessions_count' => 4,
                'available_until_time' => null,
                'sort_order' => 10,
            ],
            [
                'name' => 'AMATEUR повний день',
                'slug' => 'full-day-amateur',
                'description' => 'Повний абонемент на 6 занять.',
                'price_cents' => 200000,
                'sessions_count' => 6,
                'available_until_time' => null,
                'sort_order' => 20,
            ],
            [
                'name' => 'BASE повний день',
                'slug' => 'full-day-base',
                'description' => 'Повний абонемент на 8 занять.',
                'price_cents' => 250000,
                'sessions_count' => 8,
                'available_until_time' => null,
                'sort_order' => 30,
            ],
            [
                'name' => 'Semi pro повний день',
                'slug' => 'full-day-semi-pro',
                'description' => 'Повний абонемент на 12 занять.',
                'price_cents' => 350000,
                'sessions_count' => 12,
                'available_until_time' => null,
                'sort_order' => 40,
            ],
            [
                'name' => 'Pro повний день',
                'slug' => 'full-day-pro',
                'description' => 'Повний абонемент на 16 занять.',
                'price_cents' => 440000,
                'sessions_count' => 16,
                'available_until_time' => null,
                'sort_order' => 50,
            ],
            [
                'name' => 'START ранок',
                'slug' => 'morning-start',
                'description' => 'Ранковий абонемент на 4 заняття до 12:00.',
                'price_cents' => 140000,
                'sessions_count' => 4,
                'available_until_time' => '12:00',
                'sort_order' => 60,
            ],
            [
                'name' => 'AMATEUR ранок',
                'slug' => 'morning-amateur',
                'description' => 'Ранковий абонемент на 6 занять до 12:00.',
                'price_cents' => 190000,
                'sessions_count' => 6,
                'available_until_time' => '12:00',
                'sort_order' => 70,
            ],
            [
                'name' => 'BASE ранок',
                'slug' => 'morning-base',
                'description' => 'Ранковий абонемент на 8 занять до 12:00.',
                'price_cents' => 240000,
                'sessions_count' => 8,
                'available_until_time' => '12:00',
                'sort_order' => 80,
            ],
            [
                'name' => 'Semi pro ранок',
                'slug' => 'morning-semi-pro',
                'description' => 'Ранковий абонемент на 12 занять до 12:00.',
                'price_cents' => 310000,
                'sessions_count' => 12,
                'available_until_time' => '12:00',
                'sort_order' => 90,
            ],
            [
                'name' => 'Pro ранок',
                'slug' => 'morning-pro',
                'description' => 'Ранковий абонемент на 16 занять до 12:00.',
                'price_cents' => 390000,
                'sessions_count' => 16,
                'available_until_time' => '12:00',
                'sort_order' => 100,
            ],
        ];
    }
}
