<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Support\ActorSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeScheduledClassTrainer
{
    public function __construct(
        private readonly ActorSnapshot $actorSnapshot,
        private readonly SyncScheduledClassTrainerAlerts $syncTrainerAlerts,
    ) {}

    public function execute(Account $account, ScheduledClass $scheduledClass, Trainer $trainer, ?User $actor): ScheduledClass
    {
        abort_unless($trainer->account_id === $account->id, 404);

        return DB::transaction(function () use ($account, $scheduledClass, $trainer, $actor): ScheduledClass {
            $lockedClass = $account->scheduledClasses()
                ->with(['account', 'location', 'room', 'classType', 'trainer', 'peopleCount'])
                ->whereKey($scheduledClass->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateChange($lockedClass, $trainer);

            $change = $lockedClass->trainerChanges()->create([
                'account_id' => $account->id,
                'previous_trainer_id' => $lockedClass->trainer_id,
                'new_trainer_id' => $trainer->id,
                'previous_trainer_name' => $lockedClass->trainer?->name,
                'new_trainer_name' => $trainer->name,
                ...$this->actorSnapshot->capture($account, $actor),
            ]);

            $metadata = is_array($lockedClass->metadata) ? $lockedClass->metadata : [];
            unset($metadata[SyncTrainerSubstitutions::MetadataKey]);
            $metadata[ScheduledClass::MANUAL_TRAINER_OVERRIDE_METADATA_KEY] = [
                'trainer_change_id' => $change->id,
                'trainer_id' => $trainer->id,
                'changed_at' => now()->toIso8601String(),
            ];

            $lockedClass->forceFill([
                'trainer_id' => $trainer->id,
                'is_manually_modified' => true,
                'metadata' => $metadata,
            ])->save();

            $lockedClass->peopleCount()->update(['trainer_id' => $trainer->id]);
            $lockedClass->setRelation('trainer', $trainer);

            $this->syncTrainerAlerts->execute($account, $lockedClass, $change);

            return $lockedClass;
        });
    }

    private function validateChange(ScheduledClass $scheduledClass, Trainer $trainer): void
    {
        if (! $scheduledClass->canManuallyCorrectTrainer()) {
            throw ValidationException::withMessages([
                'trainer_id' => __('app.scheduled_class_trainer_not_editable'),
            ]);
        }

        if ($scheduledClass->trainer_id === $trainer->id) {
            throw ValidationException::withMessages([
                'trainer_id' => __('app.scheduled_class_trainer_unchanged'),
            ]);
        }

        if ($scheduledClass->ends_at->isFuture() && ! $trainer->is_active) {
            throw ValidationException::withMessages([
                'trainer_id' => __('app.scheduled_class_trainer_inactive'),
            ]);
        }
    }
}
