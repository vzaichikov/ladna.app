<?php

namespace App\Http\Requests;

use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\StudioPromoCode;
use App\Support\Promotions\PromotionCodeNormalizer;
use App\Support\ScheduleKindRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StudioPromoCodeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => app(PromotionCodeNormalizer::class)->normalize($this->input('code')),
            'class_pass_plan_ids' => $this->input('class_pass_plan_ids', []),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $account->isOwnedBy($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $promoCode = $this->route('studioPromoCode');
        $uniqueCode = Rule::unique((new StudioPromoCode)->getTable(), 'code')
            ->where('account_id', $account?->id);

        if ($promoCode instanceof StudioPromoCode) {
            $uniqueCode->ignore($promoCode);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Z0-9_-]+$/', $uniqueCode],
            'discount_type' => ['required', new Enum(PromoCodeDiscountType::class)],
            'discount_amount' => [
                'required',
                Rule::when(
                    $this->input('discount_type') === PromoCodeDiscountType::Fixed->value,
                    ['regex:/^\d{1,8}(\.\d{1,2})?$/', 'gt:0'],
                    ['integer', 'min:1', 'max:100'],
                ),
            ],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'max_total_uses' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'max_uses_per_identity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'class_pass_plan_ids' => ['required', 'array', 'min:1'],
            'class_pass_plan_ids.*' => [
                'integer',
                'distinct',
                Rule::exists((new ClassPassPlan)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('account');

            if (! $account instanceof Account || $validator->errors()->has('class_pass_plan_ids')) {
                return;
            }

            $selectedCount = ClassPassPlan::query()
                ->whereBelongsTo($account)
                ->whereIn('id', $this->input('class_pass_plan_ids', []))
                ->where('currency', $account->default_currency)
                ->whereIn('schedule_kind', ScheduleKindRegistry::classPassEligibleValues())
                ->count();

            if ($selectedCount !== count(array_unique($this->input('class_pass_plan_ids', [])))) {
                $validator->errors()->add('class_pass_plan_ids', __('app.promo_code_invalid_plans'));
            }
        }];
    }
}
