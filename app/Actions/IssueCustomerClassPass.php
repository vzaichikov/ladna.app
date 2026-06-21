<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Support\ClassPassCodeGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueCustomerClassPass
{
    public function __construct(private readonly ClassPassCodeGenerator $codeGenerator) {}

    public function execute(
        Account $account,
        Customer $customer,
        ClassPassPlan $classPassPlan,
        string $source = 'manual',
        ?Carbon $purchasedAt = null,
    ): CustomerClassPass {
        if ($customer->account_id !== $account->id || $classPassPlan->account_id !== $account->id) {
            abort(404);
        }

        if ($classPassPlan->is_trial && $customer->classBookings()->where('account_id', $account->id)->exists()) {
            throw ValidationException::withMessages([
                'class_pass_plan_id' => __('app.trial_class_pass_not_available'),
            ]);
        }

        return DB::transaction(fn (): CustomerClassPass => $account->customerClassPasses()->create([
            'customer_id' => $customer->id,
            'class_pass_plan_id' => $classPassPlan->id,
            'code' => $this->codeGenerator->unique(),
            'source' => $source,
            'status' => 'active',
            'plan_name' => $classPassPlan->name,
            'plan_slug' => $classPassPlan->slug,
            'price_cents' => $classPassPlan->price_cents,
            'currency' => $classPassPlan->currency,
            'sessions_count' => $classPassPlan->sessions_count,
            'validity_days' => $classPassPlan->validity_days,
            'purchased_at' => $purchasedAt ?? now(),
            'is_active' => true,
        ]));
    }
}
