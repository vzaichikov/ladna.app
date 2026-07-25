<?php

namespace App\Support\Salary;

use App\Models\SalaryModelVersion;
use App\Models\TrainerSalaryAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SalaryModelResolver
{
    /**
     * @param  Collection<int, TrainerSalaryAssignment>  $assignments
     */
    public function assignmentFor(Collection $assignments, int $trainerId, CarbonInterface|string $date): ?TrainerSalaryAssignment
    {
        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $assignments
            ->where('trainer_id', $trainerId)
            ->whereNull('superseded_at')
            ->filter(fn (TrainerSalaryAssignment $assignment): bool => $assignment->effective_from->toDateString() <= $dateString)
            ->sortByDesc(fn (TrainerSalaryAssignment $assignment): string => $assignment->effective_from->format('Y-m-d').'-'.str_pad((string) $assignment->id, 20, '0', STR_PAD_LEFT))
            ->first();
    }

    /**
     * @param  Collection<int, SalaryModelVersion>  $versions
     */
    public function versionFor(Collection $versions, int $salaryModelId, CarbonInterface|string $date): ?SalaryModelVersion
    {
        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $versions
            ->where('salary_model_id', $salaryModelId)
            ->whereNull('superseded_at')
            ->filter(fn (SalaryModelVersion $version): bool => $version->effective_from->toDateString() <= $dateString)
            ->sortByDesc(fn (SalaryModelVersion $version): string => $version->effective_from->format('Y-m-d').'-'.str_pad((string) $version->id, 20, '0', STR_PAD_LEFT))
            ->first();
    }
}
