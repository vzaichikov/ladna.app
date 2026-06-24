<?php

namespace Database\Factories;

use App\Enums\WebsiteLeadStatus;
use App\Models\Account;
use App\Models\WebsiteLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebsiteLead>
 */
class WebsiteLeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->name(),
            'phone' => '+380'.fake()->numerify('#########'),
            'source_page' => fake()->url(),
            'status' => WebsiteLeadStatus::New->value,
        ];
    }
}
