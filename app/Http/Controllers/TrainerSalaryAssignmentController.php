<?php

namespace App\Http\Controllers;

use App\Actions\AssignTrainerSalaryModel;
use App\Http\Requests\StoreTrainerSalaryAssignmentRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;

class TrainerSalaryAssignmentController extends Controller
{
    public function store(
        StoreTrainerSalaryAssignmentRequest $request,
        Account $account,
        AssignTrainerSalaryModel $assignTrainerSalaryModel,
    ): RedirectResponse {
        $validated = $request->validated();
        $salaryModel = $account->salaryModels()
            ->active()
            ->whereKey($validated['salary_model_id'])
            ->firstOrFail();
        $assignedCount = $assignTrainerSalaryModel->execute(
            $account,
            $salaryModel,
            $validated['trainer_ids'],
            $validated['effective_from'],
            $request->user(),
        );

        return back()->with('status', trans_choice('app.salary_trainers_assigned', $assignedCount, ['count' => $assignedCount]));
    }
}
