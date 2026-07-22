<?php

namespace App\Actions;

use App\Enums\ScheduleKind;
use App\Enums\ScheduleSeriesStatus;
use App\Models\Account;
use App\Models\AccountOnboarding;
use App\Models\Location;
use App\Models\User;
use App\Support\SlugGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishOwnerOnboarding
{
    public function __construct(private readonly GenerateAccountSchedule $generateAccountSchedule) {}

    public function execute(AccountOnboarding $onboarding, User $user): AccountOnboarding
    {
        return DB::transaction(function () use ($onboarding, $user): AccountOnboarding {
            $lockedOnboarding = AccountOnboarding::query()
                ->whereKey($onboarding->id)
                ->lockForUpdate()
                ->firstOrFail();
            $account = Account::query()->whereKey($lockedOnboarding->account_id)->lockForUpdate()->firstOrFail();

            abort_unless($account->isOwnedBy($user), 404);

            if ($lockedOnboarding->isComplete()) {
                return $lockedOnboarding->load('account');
            }

            if ($lockedOnboarding->current_step !== AccountOnboarding::LastStep) {
                throw ValidationException::withMessages([
                    'onboarding' => __('app.onboarding.complete_previous_steps'),
                ]);
            }

            if (! $user->phone_verified_at || blank($user->phone)) {
                throw ValidationException::withMessages([
                    'otp_code' => __('app.onboarding.verify_before_publish'),
                ]);
            }

            $step1 = $this->requiredStep($lockedOnboarding, 1);
            $step2 = $this->requiredStep($lockedOnboarding, 2);
            $step3 = $this->requiredStep($lockedOnboarding, 3);
            $step4 = $this->requiredStep($lockedOnboarding, 4);
            $step5 = $this->requiredStep($lockedOnboarding, 5);

            $location = $account->locations()->create([
                'name' => $step2['location_name'],
                'slug' => $this->uniqueLocationSlug($account, $step2['location_name']),
                'address' => $step2['address'],
                'timezone' => 'Europe/Kyiv',
                'is_active' => true,
                'billing_activation_pending' => false,
            ]);

            $this->createLocationPlaceholders(
                $account,
                (int) $step1['location_count'],
                $account->name,
            );

            $room = $account->rooms()->create([
                'location_id' => $location->id,
                'name' => $step2['room_name'],
                'slug' => $this->uniqueRoomSlug($location, $step2['room_name']),
                'capacity' => (int) $step2['capacity'],
                'is_active' => true,
            ]);

            $trainerType = $account->ensureDefaultTrainerType();
            $trainer = $account->trainers()->create([
                'user_id' => null,
                'trainer_type_id' => $trainerType->id,
                'name' => $step3['trainer_name'],
                'slug' => $this->uniqueTrainerSlug($account, $step3['trainer_name']),
                'is_active' => true,
            ]);

            $direction = $account->activityDirections()->create([
                'name' => $step4['direction_name'],
                'slug' => $this->uniqueDirectionSlug($account, $step4['direction_name']),
                'color' => '#A78AB9',
                'is_active' => true,
            ]);

            $classType = $account->classTypes()->create([
                'activity_direction_id' => $direction->id,
                'name' => $step4['class_name'],
                'slug' => $this->uniqueClassTypeSlug($account, $step4['class_name']),
                'color' => '#A78AB9',
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'default_duration_minutes' => (int) $step4['duration_minutes'],
                'default_capacity' => (int) $step4['capacity'],
                'is_active' => true,
            ]);

            $trainer->locations()->sync([$location->id => ['account_id' => $account->id]]);
            $trainer->activityDirections()->sync([$direction->id => ['account_id' => $account->id]]);

            $series = $account->scheduleSeries()->create([
                'location_id' => $location->id,
                'room_id' => $room->id,
                'class_type_id' => $classType->id,
                'trainer_id' => $trainer->id,
                'weekday' => (int) $step5['weekday'],
                'start_time' => $step5['start_time'],
                'start_date' => $step5['start_date'],
                'end_date' => null,
                'status' => ScheduleSeriesStatus::Active->value,
            ]);

            $account->forceFill(['allow_guest_public_booking' => true])->save();
            $this->generateAccountSchedule->execute($account, $series->id);

            $answers = $lockedOnboarding->answers ?? [];
            Arr::set($answers, 'publication', [
                'location_id' => $location->id,
                'room_id' => $room->id,
                'trainer_id' => $trainer->id,
                'activity_direction_id' => $direction->id,
                'class_type_id' => $classType->id,
                'schedule_series_id' => $series->id,
            ]);
            Arr::set($answers, 'metrics.published_at', now()->toIso8601String());

            $lockedOnboarding->forceFill([
                'answers' => $answers,
                'completed_at' => now(),
            ])->save();

            return $lockedOnboarding->load('account');
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function requiredStep(AccountOnboarding $onboarding, int $step): array
    {
        $answers = $onboarding->stepAnswers($step);

        if ($answers === []) {
            throw ValidationException::withMessages([
                'onboarding' => __('app.onboarding.complete_previous_steps'),
            ]);
        }

        return $answers;
    }

    private function createLocationPlaceholders(Account $account, int $locationCount, string $studioName): void
    {
        for ($number = 2; $number <= $locationCount; $number++) {
            $name = $studioName.' — '.$number;

            $account->locations()->create([
                'name' => $name,
                'slug' => $this->uniqueLocationSlug($account, $name),
                'timezone' => 'Europe/Kyiv',
                'is_active' => false,
                'billing_activation_pending' => false,
            ]);
        }
    }

    private function uniqueLocationSlug(Account $account, string $source): string
    {
        return SlugGenerator::unique($source, 'location', fn (string $candidate): bool => $account->locations()->where('slug', $candidate)->exists());
    }

    private function uniqueRoomSlug(Location $location, string $source): string
    {
        return SlugGenerator::unique($source, 'room', fn (string $candidate): bool => $location->rooms()->where('slug', $candidate)->exists());
    }

    private function uniqueTrainerSlug(Account $account, string $source): string
    {
        return SlugGenerator::unique($source, 'trainer', fn (string $candidate): bool => $account->trainers()->where('slug', $candidate)->exists());
    }

    private function uniqueDirectionSlug(Account $account, string $source): string
    {
        return SlugGenerator::unique($source, 'direction', fn (string $candidate): bool => $account->activityDirections()->where('slug', $candidate)->exists());
    }

    private function uniqueClassTypeSlug(Account $account, string $source): string
    {
        return SlugGenerator::unique($source, 'class', fn (string $candidate): bool => $account->classTypes()->where('slug', $candidate)->exists());
    }
}
