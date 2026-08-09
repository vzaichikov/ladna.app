<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionStatus;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use App\Models\User;
use App\Support\SlugGenerator;
use App\Support\StudioRulesHtmlSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveFestivalEdition
{
    public function __construct(
        private readonly StudioRulesHtmlSanitizer $htmlSanitizer,
        private readonly FestivalActivityRecorder $activity,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(Account $account, array $input, User $actor, ?FestivalEdition $edition = null): FestivalEdition
    {
        $series = FestivalSeries::query()->whereBelongsTo($account)->findOrFail($input['festival_series_id']);
        $timezone = (string) $input['timezone'];

        if (! $series->is_active && (! $edition?->exists || $edition->festival_series_id !== $series->id)) {
            throw ValidationException::withMessages(['festival_series_id' => __('app.festival_series_inactive')]);
        }

        $shouldGenerateSlug = ! $edition?->exists
            || ($edition->status === FestivalEditionStatus::Draft && $edition->title !== $input['title']);
        $slug = $shouldGenerateSlug
            ? SlugGenerator::unique(
                $input['title'],
                'festival',
                fn (string $candidate): bool => FestivalEdition::query()
                    ->whereBelongsTo($account)
                    ->where('slug', $candidate)
                    ->when($edition?->exists, fn ($query) => $query->whereKeyNot($edition->id))
                    ->exists(),
            )
            : (string) $edition->slug;

        if ($edition?->exists && ! $edition->status->canTransitionTo(FestivalEditionStatus::from($input['status']))) {
            throw ValidationException::withMessages(['status' => __('app.festival_status_transition_invalid')]);
        }

        return DB::transaction(function () use ($account, $input, $actor, $edition, $series, $timezone, $slug): FestivalEdition {
            $edition ??= new FestivalEdition;
            $wasExisting = $edition->exists;
            $oldStatus = $edition->status;
            $startsAt = CarbonImmutable::parse((string) $input['starts_at'], $timezone)->utc();
            $endsAt = CarbonImmutable::parse((string) $input['ends_at'], $timezone)->utc();

            $edition->fill([
                'account_id' => $account->id,
                'festival_series_id' => $series->id,
                'slug' => $slug,
                'title' => $input['title'],
                'status' => $input['status'],
                'registration_status' => $input['registration_status'],
                'summary' => $input['summary'] ?? $series->summary,
                'description_html' => $this->htmlSanitizer->sanitize($input['description_html'] ?? null),
                'rules_html' => $this->htmlSanitizer->sanitize($input['rules_html'] ?? null),
                'venue_name' => $input['venue_name'] ?? null,
                'venue_address' => $input['venue_address'] ?? null,
                'venue_map_url' => $input['venue_map_url'] ?? null,
                'venue_directions' => $input['venue_directions'] ?? null,
                'timezone' => $timezone,
                'currency' => strtoupper((string) $input['currency']),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'age_reference_date' => $input['age_reference_date'],
                'registration_opens_at' => $this->utc($input['registration_opens_at'] ?? null, $timezone),
                'registration_closes_at' => $this->utc($input['registration_closes_at'] ?? null, $timezone),
            ]);

            if ($edition->status === FestivalEditionStatus::Published && $oldStatus !== FestivalEditionStatus::Published) {
                $edition->published_at = now();
            }

            $edition->save();

            if (! $wasExisting) {
                foreach ((array) ($series->defaults['stages'] ?? []) as $index => $stage) {
                    $edition->stages()->create([
                        'account_id' => $account->id,
                        'name' => (string) ($stage['name'] ?? __('app.festival_stage_default')),
                        'description' => $stage['description'] ?? null,
                        'sort_order' => $index,
                    ]);
                }
            }

            $this->activity->record($edition, $wasExisting ? 'edition.updated' : 'edition.created', $edition, $actor, [
                'status' => $edition->status->value,
                'registration_status' => $edition->registration_status->value,
            ]);

            return $edition->refresh();
        });
    }

    private function utc(mixed $value, string $timezone): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse((string) $value, $timezone)->utc() : null;
    }
}
