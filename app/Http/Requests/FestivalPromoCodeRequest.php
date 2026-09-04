<?php

namespace App\Http\Requests;

use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalPromoCode;
use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalPromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalFinance', $account);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => app(PromotionCodeNormalizer::class)->normalize($this->input('code')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $account = $this->route('account');
        $edition = $this->route('festivalEdition');
        $promoCode = $this->route('festivalPromoCode');
        $discountType = (string) $this->input('discount_type');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'min:3',
                'max:64',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique(FestivalPromoCode::class, 'code')
                    ->where(fn (Builder $query) => $query
                        ->where('account_id', $account instanceof Account ? $account->id : 0)
                        ->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : 0))
                    ->ignore($promoCode instanceof FestivalPromoCode ? $promoCode->id : null),
            ],
            'discount_type' => ['required', Rule::enum(PromoCodeDiscountType::class)],
            'discount_value' => $discountType === PromoCodeDiscountType::Percent->value
                ? ['required', 'integer', 'min:1', 'max:100']
                : ['required', 'numeric', 'min:0.01', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'total_usage_limit' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'per_identity_usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'admission_type_ids' => ['required', 'array', 'min:1'],
            'admission_type_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(FestivalAdmissionType::class, 'id')->where(fn (Builder $query) => $query
                    ->where('account_id', $account instanceof Account ? $account->id : 0)
                    ->where('festival_edition_id', $edition instanceof FestivalEdition ? $edition->id : 0)),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
