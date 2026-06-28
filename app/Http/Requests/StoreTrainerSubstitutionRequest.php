<?php

namespace App\Http\Requests;

use App\Actions\SyncTrainerSubstitutions;
use App\Enums\TrainerSubstitutionMode;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerSubstitution;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTrainerSubstitutionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageTrainers', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->account();
        $mode = $this->mode();

        $rules = [
            'mode' => ['required', Rule::enum(TrainerSubstitutionMode::class)],
            'substitute_trainer_id' => ['required', 'integer', Rule::exists((new Trainer)->getTable(), 'id')->where('account_id', $account?->id)],
            'location_id' => ['required', 'integer', Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id)],
            'room_id' => ['required', 'integer', Rule::exists((new Room)->getTable(), 'id')->where('account_id', $account?->id)],
            'class_date' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_class_ids' => ['array', 'max:2'],
            'scheduled_class_ids.*' => ['integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'class_type_ids' => ['array'],
            'class_type_ids.*' => ['integer', Rule::exists((new ClassType)->getTable(), 'id')->where('account_id', $account?->id)],
        ];

        if ($mode === TrainerSubstitutionMode::Classes) {
            $rules['class_date'][] = 'required';
            $rules['scheduled_class_ids'][] = 'required';
            $rules['scheduled_class_ids'][] = 'min:1';
        }

        if ($mode === TrainerSubstitutionMode::Period) {
            $rules['date_from'][] = 'required';
            $rules['date_to'][] = 'required';
            $rules['class_type_ids'][] = 'required';
            $rules['class_type_ids'][] = 'min:1';
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateSubstitution($validator);
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function substitutionAttributes(): array
    {
        $account = $this->account();
        $trainer = $this->trainer();
        $mode = $this->mode();
        $location = $account->locations()->whereKey((int) $this->validated('location_id'))->firstOrFail();
        $room = $account->rooms()->whereKey((int) $this->validated('room_id'))->firstOrFail();
        $substituteTrainer = $account->trainers()->whereKey((int) $this->validated('substitute_trainer_id'))->firstOrFail();

        return [
            'account_id' => $account->id,
            'replaced_trainer_id' => $trainer->id,
            'substitute_trainer_id' => $substituteTrainer->id,
            'location_id' => $location->id,
            'room_id' => $room->id,
            'mode' => $mode->value,
            'date_from' => $mode === TrainerSubstitutionMode::Classes ? $this->validated('class_date') : $this->validated('date_from'),
            'date_to' => $mode === TrainerSubstitutionMode::Classes ? $this->validated('class_date') : $this->validated('date_to'),
            'scheduled_class_ids' => $mode === TrainerSubstitutionMode::Classes ? $this->selectedScheduledClassIds() : null,
            'class_type_ids' => $mode === TrainerSubstitutionMode::Period ? $this->selectedClassTypeIds() : null,
            'replaced_trainer_name' => $trainer->name,
            'substitute_trainer_name' => $substituteTrainer->name,
            'location_name' => $location->name,
            'room_name' => $room->name,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function selectedScheduledClassIds(): array
    {
        return collect($this->validated('scheduled_class_ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function selectedClassTypeIds(): array
    {
        return collect($this->validated('class_type_ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'scheduled_class_ids' => array_values(array_filter((array) $this->input('scheduled_class_ids', []))),
            'class_type_ids' => array_values(array_filter((array) $this->input('class_type_ids', []))),
        ]);
    }

    private function validateSubstitution(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $account = $this->account();
        $trainer = $this->trainer();
        $substituteTrainer = $account->trainers()
            ->active()
            ->whereKey((int) $this->validated('substitute_trainer_id'))
            ->first();

        if (! $substituteTrainer) {
            $validator->errors()->add('substitute_trainer_id', __('app.substitute_trainer_inactive'));

            return;
        }

        if ($substituteTrainer->is($trainer)) {
            $validator->errors()->add('substitute_trainer_id', __('app.substitute_trainer_must_differ'));
        }

        if (! $account->rooms()
            ->whereKey((int) $this->validated('room_id'))
            ->where('location_id', (int) $this->validated('location_id'))
            ->exists()) {
            $validator->errors()->add('room_id', __('app.room_location_mismatch'));
        }

        if ($this->mode() === TrainerSubstitutionMode::Classes) {
            $this->validateClassesMode($validator, $account, $trainer, $substituteTrainer);

            return;
        }

        $this->validatePeriodMode($validator, $account, $trainer, $substituteTrainer);
    }

    private function validateClassesMode(Validator $validator, Account $account, Trainer $trainer, Trainer $substituteTrainer): void
    {
        $timezone = $this->timezone($account);
        $classDate = CarbonImmutable::parse((string) $this->validated('class_date'), $timezone)->startOfDay();
        $minimumPastDate = CarbonImmutable::now($timezone)->subDays(2)->startOfDay();

        if ($classDate->lessThan($minimumPastDate)) {
            $validator->errors()->add('class_date', __('app.trainer_substitution_past_limit'));

            return;
        }

        $classes = $this->selectedClasses($account, $trainer, $timezone);

        if ($classes->count() !== count($this->selectedScheduledClassIds())) {
            $validator->errors()->add('scheduled_class_ids', __('app.trainer_substitution_classes_invalid'));

            return;
        }

        if ($classes->isEmpty()) {
            $validator->errors()->add('scheduled_class_ids', __('app.trainer_substitution_classes_required'));

            return;
        }

        $this->validateOverlapForClasses($validator, $classes, $timezone);
        $this->validateSubstituteConflicts($validator, $account, $classes, $substituteTrainer);
    }

    private function validatePeriodMode(Validator $validator, Account $account, Trainer $trainer, Trainer $substituteTrainer): void
    {
        $timezone = $this->timezone($account);
        $dateFrom = CarbonImmutable::parse((string) $this->validated('date_from'), $timezone)->startOfDay();
        $dateTo = CarbonImmutable::parse((string) $this->validated('date_to'), $timezone)->endOfDay();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $minimumStartDate = $this->minimumPeriodStartDate($today);

        if ($dateFrom->lessThan($minimumStartDate)) {
            $validator->errors()->add('date_from', __('app.trainer_substitution_period_today_or_future'));

            return;
        }

        $candidateClasses = $this->periodCandidateClasses($account, $trainer, $dateFrom, $dateTo, $timezone);

        $this->validateOverlapForPeriod($validator, $candidateClasses, $dateFrom, $dateTo, $timezone);
        $this->validateSubstituteConflicts($validator, $account, $candidateClasses, $substituteTrainer);
    }

    /**
     * @return Collection<int, ScheduledClass>
     */
    private function selectedClasses(Account $account, Trainer $trainer, string $timezone): Collection
    {
        $date = CarbonImmutable::parse((string) $this->validated('class_date'), $timezone);

        return $account->scheduledClasses()
            ->whereIn('id', $this->selectedScheduledClassIds())
            ->where('location_id', (int) $this->validated('location_id'))
            ->where('room_id', (int) $this->validated('room_id'))
            ->whereBetween('starts_at', [
                $date->startOfDay()->timezone(config('app.timezone')),
                $date->endOfDay()->timezone(config('app.timezone')),
            ])
            ->get()
            ->filter(fn (ScheduledClass $scheduledClass): bool => $this->originalTrainerId($scheduledClass) === $trainer->id)
            ->values();
    }

    /**
     * @return Collection<int, ScheduledClass>
     */
    private function periodCandidateClasses(Account $account, Trainer $trainer, CarbonImmutable $dateFrom, CarbonImmutable $dateTo, string $timezone): Collection
    {
        return $account->scheduledClasses()
            ->where('location_id', (int) $this->validated('location_id'))
            ->where('room_id', (int) $this->validated('room_id'))
            ->whereIn('class_type_id', $this->selectedClassTypeIds())
            ->whereBetween('starts_at', [
                $dateFrom->timezone(config('app.timezone')),
                $dateTo->timezone(config('app.timezone')),
            ])
            ->get()
            ->filter(fn (ScheduledClass $scheduledClass): bool => $this->originalTrainerId($scheduledClass) === $trainer->id
                && $scheduledClass->starts_at->copy()->timezone($timezone)->toDateString() >= $dateFrom->toDateString()
                && $scheduledClass->starts_at->copy()->timezone($timezone)->toDateString() <= $dateTo->toDateString())
            ->values();
    }

    /**
     * @param  Collection<int, ScheduledClass>  $classes
     */
    private function validateOverlapForClasses(Validator $validator, Collection $classes, string $timezone): void
    {
        $existingSubstitutions = $this->overlappingSubstitutions(
            CarbonImmutable::parse((string) $this->validated('class_date'), $timezone)->startOfDay(),
            CarbonImmutable::parse((string) $this->validated('class_date'), $timezone)->endOfDay(),
        );

        foreach ($classes as $scheduledClass) {
            if ($existingSubstitutions->contains(fn (TrainerSubstitution $substitution): bool => $this->substitutionMatchesClass($substitution, $scheduledClass, $timezone))) {
                $validator->errors()->add('scheduled_class_ids', __('app.trainer_substitution_overlap'));

                return;
            }
        }
    }

    /**
     * @param  Collection<int, ScheduledClass>  $candidateClasses
     */
    private function validateOverlapForPeriod(
        Validator $validator,
        Collection $candidateClasses,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
        string $timezone,
    ): void {
        $existingSubstitutions = $this->overlappingSubstitutions($dateFrom, $dateTo);
        $classTypeIds = $this->selectedClassTypeIds();
        $candidateClassIds = $candidateClasses->pluck('id')->all();

        foreach ($existingSubstitutions as $substitution) {
            if ($substitution->mode === TrainerSubstitutionMode::Period
                && (int) $substitution->location_id === (int) $this->validated('location_id')
                && (int) $substitution->room_id === (int) $this->validated('room_id')
                && array_intersect($this->ids($substitution->class_type_ids), $classTypeIds) !== []) {
                $validator->errors()->add('class_type_ids', __('app.trainer_substitution_overlap'));

                return;
            }

            if ($substitution->mode === TrainerSubstitutionMode::Classes
                && array_intersect($this->ids($substitution->scheduled_class_ids), $candidateClassIds) !== []) {
                $validator->errors()->add('class_type_ids', __('app.trainer_substitution_overlap'));

                return;
            }

            if ($candidateClasses->contains(fn (ScheduledClass $scheduledClass): bool => $this->substitutionMatchesClass($substitution, $scheduledClass, $timezone))) {
                $validator->errors()->add('class_type_ids', __('app.trainer_substitution_overlap'));

                return;
            }
        }
    }

    /**
     * @param  Collection<int, ScheduledClass>  $classes
     */
    private function validateSubstituteConflicts(Validator $validator, Account $account, Collection $classes, Trainer $substituteTrainer): void
    {
        foreach ($classes as $scheduledClass) {
            $hasConflict = $account->scheduledClasses()
                ->whereKeyNot($scheduledClass->id)
                ->whereBelongsTo($substituteTrainer, 'trainer')
                ->where('starts_at', '<', $scheduledClass->ends_at)
                ->where('ends_at', '>', $scheduledClass->starts_at)
                ->exists();

            if ($hasConflict) {
                $validator->errors()->add('substitute_trainer_id', __('app.substitute_trainer_conflict'));

                return;
            }
        }
    }

    /**
     * @return Collection<int, TrainerSubstitution>
     */
    private function overlappingSubstitutions(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): Collection
    {
        return $this->account()
            ->trainerSubstitutions()
            ->where('replaced_trainer_id', $this->trainer()->id)
            ->whereDate('date_to', '>=', $dateFrom->toDateString())
            ->whereDate('date_from', '<=', $dateTo->toDateString())
            ->when($this->currentSubstitution(), fn ($query, TrainerSubstitution $substitution) => $query->whereKeyNot($substitution->id))
            ->get();
    }

    private function substitutionMatchesClass(TrainerSubstitution $substitution, ScheduledClass $scheduledClass, string $timezone): bool
    {
        $displayDate = $scheduledClass->starts_at->copy()->timezone($timezone)->toDateString();

        if ($substitution->date_from->toDateString() > $displayDate || $substitution->date_to->toDateString() < $displayDate) {
            return false;
        }

        if ($substitution->mode === TrainerSubstitutionMode::Classes) {
            return in_array($scheduledClass->id, $this->ids($substitution->scheduled_class_ids), true);
        }

        return (int) $substitution->location_id === (int) $scheduledClass->location_id
            && (int) $substitution->room_id === (int) $scheduledClass->room_id
            && in_array((int) $scheduledClass->class_type_id, $this->ids($substitution->class_type_ids), true);
    }

    private function originalTrainerId(ScheduledClass $scheduledClass): int
    {
        $metadata = $scheduledClass->metadata;

        if (is_array($metadata) && is_array($metadata[SyncTrainerSubstitutions::MetadataKey] ?? null)) {
            return (int) ($metadata[SyncTrainerSubstitutions::MetadataKey]['original_trainer_id'] ?? $scheduledClass->trainer_id);
        }

        return (int) $scheduledClass->trainer_id;
    }

    private function mode(): ?TrainerSubstitutionMode
    {
        return TrainerSubstitutionMode::tryFrom((string) $this->input('mode'));
    }

    private function account(): Account
    {
        return $this->route('account');
    }

    private function trainer(): Trainer
    {
        return $this->route('trainer');
    }

    private function currentSubstitution(): ?TrainerSubstitution
    {
        $substitution = $this->route('trainerSubstitution');

        return $substitution instanceof TrainerSubstitution ? $substitution : null;
    }

    private function minimumPeriodStartDate(CarbonImmutable $today): CarbonImmutable
    {
        $substitution = $this->currentSubstitution();

        if (! $substitution?->isPeriodMode()) {
            return $today;
        }

        $existingStartDate = CarbonImmutable::instance($substitution->date_from)->startOfDay();

        return $existingStartDate->lessThan($today) ? $existingStartDate : $today;
    }

    /**
     * @param  array<int, mixed>|null  $values
     * @return array<int, int>
     */
    private function ids(?array $values): array
    {
        return collect($values ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    private function timezone(Account $account): string
    {
        return $account->timezone ?: config('app.timezone');
    }
}
