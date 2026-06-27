<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerClassPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && ($this->user()?->can('issueCustomerClassPasses', $account) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'class_pass_plan_id' => [
                'required',
                'integer',
                Rule::exists((new ClassPassPlan)->getTable(), 'id')
                    ->where('account_id', $account?->id)
                    ->where('is_active', true),
            ],
            'issued_location_id' => [
                'required',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')
                    ->where('account_id', $account?->id)
                    ->where('is_active', true),
            ],
            'is_paid' => ['nullable', 'boolean'],
        ];
    }
}
