<?php

namespace App\Http\Controllers;

use App\Actions\GenerateAccountSchedule;
use App\Actions\SyncTrainerSubstitutions;
use App\Enums\TrainerSubstitutionMode;
use App\Http\Requests\StoreTrainerSubstitutionRequest;
use App\Http\Requests\UpdateTrainerSubstitutionRequest;
use App\Models\Account;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerSubstitution;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrainerSubstitutionController extends Controller
{
    public function classes(Request $request, Account $account, Trainer $trainer): JsonResponse
    {
        $this->ensureTrainerBelongsToAccount($account, $trainer);
        $this->authorize('manageTrainers', $account);

        $validated = $request->validate([
            'location_id' => ['required', Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account->id)],
            'room_id' => ['required', Rule::exists((new Room)->getTable(), 'id')->where('account_id', $account->id)],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        abort_unless($account->rooms()
            ->whereKey((int) $validated['room_id'])
            ->where('location_id', (int) $validated['location_id'])
            ->exists(), 422);

        $timezone = $account->timezone ?: config('app.timezone');
        $date = CarbonImmutable::parse($validated['date'], $timezone);
        $minimumPastDate = CarbonImmutable::now($timezone)->subDays(2)->startOfDay();

        if ($date->startOfDay()->lessThan($minimumPastDate)) {
            return response()->json(['data' => []]);
        }

        $classes = $account->scheduledClasses()
            ->with(['classType:id,name', 'trainer:id,name'])
            ->where('location_id', (int) $validated['location_id'])
            ->where('room_id', (int) $validated['room_id'])
            ->whereBetween('starts_at', [
                $date->startOfDay()->timezone(config('app.timezone')),
                $date->endOfDay()->timezone(config('app.timezone')),
            ])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (ScheduledClass $scheduledClass): bool => $this->originalTrainerId($scheduledClass) === $trainer->id)
            ->values()
            ->map(fn (ScheduledClass $scheduledClass): array => [
                'id' => $scheduledClass->id,
                'time' => $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone())->format('H:i'),
                'title' => $scheduledClass->title,
                'class_type' => $scheduledClass->classType?->name,
                'current_trainer' => $scheduledClass->trainer?->name,
            ]);

        return response()->json(['data' => $classes]);
    }

    public function store(
        StoreTrainerSubstitutionRequest $request,
        Account $account,
        Trainer $trainer,
        GenerateAccountSchedule $generateAccountSchedule,
        SyncTrainerSubstitutions $syncTrainerSubstitutions,
    ): RedirectResponse {
        $this->ensureTrainerBelongsToAccount($account, $trainer);
        $attributes = $request->substitutionAttributes();

        $substitution = $account->trainerSubstitutions()->create($attributes);

        $this->resync(
            $account,
            $generateAccountSchedule,
            $syncTrainerSubstitutions,
            $substitution->mode === TrainerSubstitutionMode::Period,
            $request->selectedScheduledClassIds(),
        );

        return redirect()->route('dashboard.accounts.trainers.edit', [$account, $trainer])
            ->with('status', __('app.trainer_substitution_created'));
    }

    public function update(
        UpdateTrainerSubstitutionRequest $request,
        Account $account,
        Trainer $trainer,
        TrainerSubstitution $trainerSubstitution,
        GenerateAccountSchedule $generateAccountSchedule,
        SyncTrainerSubstitutions $syncTrainerSubstitutions,
    ): RedirectResponse {
        $this->ensureTrainerBelongsToAccount($account, $trainer);
        $this->ensureSubstitutionBelongsToTrainer($account, $trainer, $trainerSubstitution);

        $oldMode = $trainerSubstitution->mode;
        $oldClassIds = $this->ids($trainerSubstitution->scheduled_class_ids);

        $trainerSubstitution->update($request->substitutionAttributes());

        $classIds = collect($oldClassIds)
            ->merge($request->selectedScheduledClassIds())
            ->unique()
            ->values()
            ->all();

        $this->resync(
            $account,
            $generateAccountSchedule,
            $syncTrainerSubstitutions,
            $oldMode === TrainerSubstitutionMode::Period || $trainerSubstitution->fresh()->mode === TrainerSubstitutionMode::Period,
            $classIds,
        );

        return redirect()->route('dashboard.accounts.trainers.edit', [$account, $trainer])
            ->with('status', __('app.trainer_substitution_updated'));
    }

    public function destroy(
        Account $account,
        Trainer $trainer,
        TrainerSubstitution $trainerSubstitution,
        GenerateAccountSchedule $generateAccountSchedule,
        SyncTrainerSubstitutions $syncTrainerSubstitutions,
    ): RedirectResponse {
        $this->ensureTrainerBelongsToAccount($account, $trainer);
        $this->ensureSubstitutionBelongsToTrainer($account, $trainer, $trainerSubstitution);
        $mode = $trainerSubstitution->mode;
        $classIds = $this->ids($trainerSubstitution->scheduled_class_ids);

        $trainerSubstitution->delete();

        $this->resync($account, $generateAccountSchedule, $syncTrainerSubstitutions, $mode === TrainerSubstitutionMode::Period, $classIds);

        return redirect()->route('dashboard.accounts.trainers.edit', [$account, $trainer])
            ->with('status', __('app.trainer_substitution_deleted'));
    }

    /**
     * @param  array<int, int>  $classIds
     */
    private function resync(
        Account $account,
        GenerateAccountSchedule $generateAccountSchedule,
        SyncTrainerSubstitutions $syncTrainerSubstitutions,
        bool $regenerateSchedule,
        array $classIds = [],
    ): void {
        if ($regenerateSchedule) {
            $generateAccountSchedule->execute($account);
        }

        if ($classIds !== []) {
            $syncTrainerSubstitutions->syncAfterSubstitutionChange($account, $classIds);
        }
    }

    private function ensureTrainerBelongsToAccount(Account $account, Trainer $trainer): void
    {
        abort_unless($trainer->account_id === $account->id, 404);
    }

    private function ensureSubstitutionBelongsToTrainer(Account $account, Trainer $trainer, TrainerSubstitution $trainerSubstitution): void
    {
        abort_unless(
            $trainerSubstitution->account_id === $account->id
            && $trainerSubstitution->replaced_trainer_id === $trainer->id,
            404,
        );
    }

    private function originalTrainerId(ScheduledClass $scheduledClass): int
    {
        $metadata = $scheduledClass->metadata;

        if (is_array($metadata) && is_array($metadata[SyncTrainerSubstitutions::MetadataKey] ?? null)) {
            return (int) ($metadata[SyncTrainerSubstitutions::MetadataKey]['original_trainer_id'] ?? $scheduledClass->trainer_id);
        }

        return (int) $scheduledClass->trainer_id;
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
}
