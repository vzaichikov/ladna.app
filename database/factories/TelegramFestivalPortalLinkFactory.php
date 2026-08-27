<?php

namespace Database\Factories;

use App\Enums\TelegramBotProfile;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramFestivalPortalLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramFestivalPortalLink>
 */
class TelegramFestivalPortalLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'festival_portal_user_id' => FestivalPortalUser::factory(),
            'account_id' => fn (array $attributes): int => FestivalPortalUser::query()
                ->findOrFail($attributes['festival_portal_user_id'])
                ->account_id,
            'telegram_chat_authorization_id' => function (array $attributes): int {
                $portalUser = FestivalPortalUser::query()->findOrFail($attributes['festival_portal_user_id']);
                $series = FestivalSeries::factory()->for($portalUser->account)->create();
                $installation = TelegramBotInstallation::factory()->for($portalUser->account)->create([
                    'scope_type' => 'festival_series',
                    'scope_id' => $series->id,
                    'profile' => TelegramBotProfile::Festival,
                ]);

                return TelegramChatAuthorization::factory()
                    ->for($portalUser->account)
                    ->for($installation, 'installation')
                    ->create([
                        'profile' => TelegramBotProfile::Festival,
                        'user_id' => null,
                    ])->id;
            },
        ];
    }
}
