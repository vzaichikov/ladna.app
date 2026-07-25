<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\SalaryModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignTrainerSalaryModel
{
    /**
     * @param  array<int, int>  $trainerIds
     */
    public function execute(
        Account $account,
        SalaryModel $salaryModel,
        array $trainerIds,
        string $effectiveFrom,
        ?User $actor,
    ): int {
        abort_unless($salaryModel->account_id === $account->id && $salaryModel->archived_at === null, 404);

        return DB::transaction(function () use ($account, $salaryModel, $trainerIds, $effectiveFrom, $actor): int {
            $trainers = $account->trainers()
                ->whereKey($trainerIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'account_id']);
            abort_unless($trainers->count() === count($trainerIds), 422);

            foreach ($trainers as $trainer) {
                $trainer->salaryAssignments()
                    ->whereDate('effective_from', $effectiveFrom)
                    ->whereNull('superseded_at')
                    ->update(['superseded_at' => now()]);

                $trainer->salaryAssignments()->create([
                    'account_id' => $account->id,
                    'salary_model_id' => $salaryModel->id,
                    'created_by_user_id' => $actor?->id,
                    'effective_from' => $effectiveFrom,
                ]);
            }

            return $trainers->count();
        }, attempts: 3);
    }
}
