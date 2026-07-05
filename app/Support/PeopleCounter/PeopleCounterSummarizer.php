<?php

namespace App\Support\PeopleCounter;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\PeopleCounterSample;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassPeopleCount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PeopleCounterSummarizer
{
    public function __construct(private readonly PeopleCounterStudioHours $studioHours) {}

    /**
     * @param  callable(string, array<string, mixed>): void|null  $debug
     */
    public function summarizeEndedClasses(?Carbon $now = null, int $limit = 200, ?callable $debug = null): int
    {
        $now ??= now();
        $endedBefore = $now->copy()->subMinutes(PeopleCounterSamplingWindow::SummarizeDelayMinutes);
        $openAccountIds = $this->studioHours->openAccountIds($now);
        $candidateCount = ScheduledClass::query()
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('ends_at', '<=', $endedBefore)
            ->whereDoesntHave('peopleCount')
            ->count();
        $classes = ScheduledClass::query()
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('ends_at', '<=', $endedBefore)
            ->whereDoesntHave('peopleCount')
            ->whereIn('account_id', $openAccountIds)
            ->with(['account:id,status,enable_people_counter,timezone,opening_hours', 'classType:id,account_id,schedule_kind', 'room'])
            ->orderBy('ends_at')
            ->limit($limit)
            ->get();

        $debug?->__invoke('summarize.selection', [
            'now' => $now->toDateTimeString(),
            'ended_before_or_at' => $endedBefore->toDateTimeString(),
            'summarize_delay_minutes' => PeopleCounterSamplingWindow::SummarizeDelayMinutes,
            'limit' => $limit,
            'candidate_classes' => $candidateCount,
            'open_people_counter_studios' => count($openAccountIds),
            'eligible_classes' => $classes->count(),
        ]);

        $classes->each(fn (ScheduledClass $scheduledClass): ScheduledClassPeopleCount => $this->summarizeClass($scheduledClass, $debug));

        return $classes->count();
    }

    /**
     * @param  callable(string, array<string, mixed>): void|null  $debug
     */
    public function summarizeClass(ScheduledClass $scheduledClass, ?callable $debug = null): ScheduledClassPeopleCount
    {
        $scheduledClass->loadMissing(['classType', 'room']);
        $trimStart = $scheduledClass->starts_at->copy()->addMinutes(PeopleCounterSamplingWindow::StartBufferMinutes);
        $trimEnd = $scheduledClass->ends_at->copy()->subMinutes(PeopleCounterSamplingWindow::EndBufferMinutes);
        $samples = $trimStart->lessThanOrEqualTo($trimEnd)
            ? $scheduledClass->peopleCounterSamples()
                ->whereBetween('captured_at', [$trimStart, $trimEnd])
                ->orderBy('captured_at')
                ->get()
            : collect();
        $successfulSamples = $samples
            ->where('status', PeopleCounterSample::StatusSucceeded)
            ->filter(fn (PeopleCounterSample $sample): bool => $sample->detected_count !== null);
        $failedSamplesCount = $samples->count() - $successfulSamples->count();
        $attendedCount = $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->where('status', ClassBookingStatus::Attended->value)
            ->count();
        $expectedPeopleCount = $this->expectedPeopleCount($scheduledClass, $attendedCount);

        $detectedCount = null;
        $delta = null;
        $status = ScheduledClassPeopleCount::StatusInsufficientData;

        if (! $scheduledClass->room?->hasEnabledRtspCamera()) {
            $status = ScheduledClassPeopleCount::StatusNoCamera;
        } elseif ($successfulSamples->isNotEmpty()) {
            $detectedCount = $this->percentile75($successfulSamples->pluck('detected_count'));
            $delta = $detectedCount - $expectedPeopleCount;
            $status = $delta === 0
                ? ScheduledClassPeopleCount::StatusMatched
                : ScheduledClassPeopleCount::StatusMismatch;
        }

        $summary = ScheduledClassPeopleCount::updateOrCreate(
            ['scheduled_class_id' => $scheduledClass->id],
            [
                'account_id' => $scheduledClass->account_id,
                'location_id' => $scheduledClass->location_id,
                'room_id' => $scheduledClass->room_id,
                'trainer_id' => $scheduledClass->trainer_id,
                'status' => $status,
                'attended_count' => $attendedCount,
                'detected_count' => $detectedCount,
                'delta' => $delta,
                'successful_samples_count' => $successfulSamples->count(),
                'failed_samples_count' => $failedSamplesCount,
                'first_sampled_at' => $samples->first()?->captured_at,
                'last_sampled_at' => $samples->last()?->captured_at,
                'summarized_at' => now(),
            ],
        );

        $debug?->__invoke('summarize.class.finished', [
            'scheduled_class_id' => $scheduledClass->id,
            'account_id' => $scheduledClass->account_id,
            'location_id' => $scheduledClass->location_id,
            'room_id' => $scheduledClass->room_id,
            'trim_start' => $trimStart->toDateTimeString(),
            'trim_end' => $trimEnd->toDateTimeString(),
            'start_buffer_minutes' => PeopleCounterSamplingWindow::StartBufferMinutes,
            'end_buffer_minutes' => PeopleCounterSamplingWindow::EndBufferMinutes,
            'status' => $summary->status,
            'attended_count' => $summary->attended_count,
            'expected_people_count' => $expectedPeopleCount,
            'trainer_adjustment' => $expectedPeopleCount - $attendedCount,
            'detected_count' => $summary->detected_count,
            'delta' => $summary->delta,
            'successful_samples_count' => $summary->successful_samples_count,
            'failed_samples_count' => $summary->failed_samples_count,
            'first_sampled_at' => $summary->first_sampled_at?->toDateTimeString(),
            'last_sampled_at' => $summary->last_sampled_at?->toDateTimeString(),
        ]);

        return $summary;
    }

    private function expectedPeopleCount(ScheduledClass $scheduledClass, int $attendedCount): int
    {
        if ($scheduledClass->classType?->schedule_kind === ScheduleKind::GroupClass) {
            return $attendedCount + 1;
        }

        return $attendedCount;
    }

    /**
     * @param  Collection<int, int|null>  $values
     */
    private function percentile75(Collection $values): int
    {
        $sorted = $values
            ->filter(fn (?int $value): bool => $value !== null)
            ->map(fn (int $value): int => $value)
            ->sort()
            ->values();

        $index = max(0, (int) ceil($sorted->count() * 0.75) - 1);

        return (int) $sorted->get($index, 0);
    }
}
