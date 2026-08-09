<?php

namespace App\Actions\Festivals;

use App\Models\Account;
use App\Models\FestivalSeries;
use App\Models\User;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

class SaveFestivalSeries
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    /** @param array<string, mixed> $input */
    public function execute(Account $account, array $input, User $actor, ?FestivalSeries $series = null): FestivalSeries
    {
        return DB::transaction(function () use ($account, $input, $actor, $series): FestivalSeries {
            $series ??= new FestivalSeries;
            $wasExisting = $series->exists;
            $slug = $wasExisting
                ? $series->slug
                : SlugGenerator::unique(
                    $input['name'],
                    'festival-series',
                    fn (string $candidate): bool => FestivalSeries::query()
                        ->whereBelongsTo($account)
                        ->where('slug', $candidate)
                        ->exists(),
                );

            $series->fill([
                'account_id' => $account->id,
                'name' => $input['name'],
                'slug' => $slug,
                'summary' => $input['summary'] ?? null,
                'organizer_name' => $input['organizer_name'] ?? null,
                'organizer_email' => $input['organizer_email'] ?? null,
                'organizer_phone' => $input['organizer_phone'] ?? null,
                'organizer_telegram_url' => $input['organizer_telegram_url'] ?? null,
                'organizer_instagram_url' => $input['organizer_instagram_url'] ?? null,
                'brand_color' => $input['brand_color'] ?? null,
                'is_active' => $input['is_active'] ?? ! $wasExisting,
            ])->save();

            $this->activity->record($series, $wasExisting ? 'series.updated' : 'series.created', null, $actor, [
                'is_active' => $series->is_active,
            ]);

            return $series->refresh();
        });
    }
}
