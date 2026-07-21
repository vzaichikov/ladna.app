<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionPriceStatus;
use App\Models\SubscriptionPriceVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignAccountTariffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subscription_price_version_id' => [
                'required',
                'integer',
                Rule::exists((new SubscriptionPriceVersion)->getTable(), 'id')
                    ->where('status', SubscriptionPriceStatus::Published->value)
                    ->where(function (Builder $query): void {
                        $query
                            ->whereNotNull('effective_at')
                            ->where('effective_at', '<=', now());
                    }),
            ],
        ];
    }
}
