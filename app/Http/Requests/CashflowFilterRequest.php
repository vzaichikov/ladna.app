<?php

namespace App\Http\Requests;

use App\Models\Account;

class CashflowFilterRequest extends AccountPaymentFilterRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('view', $account) ?? false)
            && ($this->user()?->can('manageStudioCashflow', $account) ?? false);
    }
}
