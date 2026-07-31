<?php

namespace App\Actions;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderClassPassPlans
{
    /**
     * @param  array<int, mixed>  $planIds
     * @return array{plan_ids: array<int, int>, sort_orders: array<int, int>}
     */
    public function execute(Account $account, string $scheduleKind, ?int $segmentId, array $planIds): array
    {
        $normalizedPlanIds = collect($planIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return DB::transaction(function () use ($account, $scheduleKind, $segmentId, $normalizedPlanIds): array {
            $plans = $account->classPassPlans()
                ->where('schedule_kind', $scheduleKind)
                ->when(
                    $segmentId === null,
                    fn ($query) => $query->whereNull('class_pass_segment_id'),
                    fn ($query) => $query->where('class_pass_segment_id', $segmentId),
                )
                ->lockForUpdate()
                ->get(['id', 'sort_order'])
                ->keyBy('id');
            $expectedPlanIds = $plans->keys()
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $submittedPlanIds = collect($normalizedPlanIds)->sort()->values()->all();

            if ($submittedPlanIds !== $expectedPlanIds) {
                throw ValidationException::withMessages([
                    'plan_ids' => __('app.class_pass_plan_order_scope_invalid'),
                ]);
            }

            $sortOrders = [];

            foreach ($normalizedPlanIds as $index => $planId) {
                $sortOrder = ($index + 1) * 10;
                $plan = $plans->get($planId);

                if ((int) $plan->sort_order !== $sortOrder) {
                    $plan->update(['sort_order' => $sortOrder]);
                }

                $sortOrders[$planId] = $sortOrder;
            }

            return [
                'plan_ids' => $normalizedPlanIds,
                'sort_orders' => $sortOrders,
            ];
        }, attempts: 3);
    }
}
