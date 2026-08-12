<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEntryStatus;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryStep;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReserveFestivalEntryTrack
{
    public function execute(FestivalEntry $entry, FestivalEntryStep $step): void
    {
        $step->loadMissing('requirements.definition', 'requirements.submissions');
        $values = $step->requirements
            ->filter(fn ($requirement): bool => in_array($requirement->definition->code, ['music_artist', 'music_title'], true))
            ->mapWithKeys(fn ($requirement): array => [
                $requirement->definition->code => data_get($requirement->submissions->first()?->value_json, 'value'),
            ]);

        if ($values->isEmpty()) {
            return;
        }
        if (blank($values['music_artist'] ?? null) || blank($values['music_title'] ?? null)) {
            throw ValidationException::withMessages(['step' => __('app.festival_step_requirements_incomplete')]);
        }

        $artist = Str::squish((string) $values['music_artist']);
        $title = Str::squish((string) $values['music_title']);
        $normalized = mb_strtolower(Str::squish($artist).'|'.Str::squish($title));
        $key = hash('sha256', $normalized);
        $duplicate = FestivalEntry::query()
            ->where('festival_category_id', $entry->festival_category_id)
            ->where('normalized_track_key', $key)
            ->whereKeyNot($entry->id)
            ->whereNotIn('status', [FestivalEntryStatus::Rejected->value, FestivalEntryStatus::Withdrawn->value])
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['step' => __('app.festival_track_already_reserved')]);
        }

        try {
            $entry->forceFill([
                'track_artist' => $artist,
                'track_title' => $title,
                'normalized_track_key' => $key,
                'track_reserved_at' => now(),
            ])->save();
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages(['step' => __('app.festival_track_already_reserved')]);
        }
    }
}
