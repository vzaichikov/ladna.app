<?php

namespace Database\Factories;

use App\Models\FestivalCategory;
use App\Models\FestivalEntry;
use App\Models\FestivalPortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FestivalEntry> */
class FestivalEntryFactory extends Factory
{
    protected $model = FestivalEntry::class;

    public function definition(): array
    {
        return ['account_id' => fn (array $attributes) => FestivalCategory::findOrFail($attributes['festival_category_id'])->account_id, 'festival_edition_id' => fn (array $attributes) => FestivalCategory::findOrFail($attributes['festival_category_id'])->festival_edition_id, 'festival_portal_user_id' => fn (array $attributes) => FestivalPortalUser::factory()->create(['account_id' => FestivalCategory::findOrFail($attributes['festival_category_id'])->account_id])->id, 'festival_category_id' => FestivalCategory::factory(), 'code' => 'FE-'.Str::upper(Str::random(8)), 'performer_name' => fake()->name(), 'act_title' => fake()->sentence(3), 'status' => 'draft', 'qualification_status' => 'not_required'];
    }
}
