<?php

namespace App\Support\Festivals;

use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalParticipant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FestivalRuleRegistry
{
    /** @param Collection<int, FestivalParticipant> $participants */
    public function validateEntry(FestivalEdition $edition, FestivalCategory $category, Collection $participants, bool $enforceRegistrationWindow = true): void
    {
        abort_unless($category->account_id === $edition->account_id && $category->festival_edition_id === $edition->id, 404);

        if ($enforceRegistrationWindow && ! $edition->registrationIsOpen()) {
            throw ValidationException::withMessages(['edition' => __('app.festival_registration_not_open')]);
        }

        if ($enforceRegistrationWindow && $category->registration_closes_at?->isPast()) {
            throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_deadline_passed')]);
        }

        if ($participants->count() < $category->min_members || $participants->count() > $category->max_members) {
            throw ValidationException::withMessages(['participant_ids' => __('app.festival_participant_count_invalid')]);
        }

        foreach ($participants as $participant) {
            $age = $participant->date_of_birth->diffInYears($edition->age_reference_date);
            if (($category->min_age !== null && $age < $category->min_age) || ($category->max_age !== null && $age > $category->max_age)) {
                throw ValidationException::withMessages(['participant_ids' => __('app.festival_participant_age_invalid')]);
            }
        }
    }

    public function validateEntrySnapshot(FestivalEdition $edition, FestivalEntry $entry, bool $enforceRegistrationWindow = true): void
    {
        abort_unless($entry->account_id === $edition->account_id && $entry->festival_edition_id === $edition->id, 404);

        if ($enforceRegistrationWindow && ! $edition->registrationIsOpen()) {
            throw ValidationException::withMessages(['edition' => __('app.festival_registration_not_open')]);
        }

        $snapshot = $entry->category_snapshot ?? [];
        $registrationClosesAt = data_get($snapshot, 'registration_closes_at');
        if ($enforceRegistrationWindow && $registrationClosesAt && now()->isAfter($registrationClosesAt)) {
            throw ValidationException::withMessages(['festival_category_id' => __('app.festival_category_deadline_passed')]);
        }

        $rules = (array) data_get($snapshot, 'rules', []);
        $participants = $entry->participants;
        $minimumMembers = (int) ($rules['min_members'] ?? 1);
        $maximumMembers = (int) ($rules['max_members'] ?? 1);
        if ($participants->count() < $minimumMembers || $participants->count() > $maximumMembers) {
            throw ValidationException::withMessages(['participant_ids' => __('app.festival_participant_count_invalid')]);
        }

        foreach ($participants as $participant) {
            $age = (int) ($participant->pivot->age_snapshot ?? $participant->date_of_birth->diffInYears($edition->age_reference_date));
            if (($rules['min_age'] ?? null) !== null && $age < (int) $rules['min_age']
                || ($rules['max_age'] ?? null) !== null && $age > (int) $rules['max_age']) {
                throw ValidationException::withMessages(['participant_ids' => __('app.festival_participant_age_invalid')]);
            }
        }
    }
}
