<?php

namespace App\Http\Requests;

use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventPromoCode;
use App\Models\EventTicketType;
use App\Support\Promotions\PromotionCodeNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class SaveEventPromoCodeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => app(PromotionCodeNormalizer::class)->normalize($this->input('code')),
            'ticket_type_ids' => $this->input('ticket_type_ids', []),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageEvents', $account);
    }

    public function rules(): array
    {
        $account = $this->route('account');
        $event = $this->route('event');
        $promoCode = $this->route('eventPromoCode');
        $uniqueCode = Rule::unique((new EventPromoCode)->getTable(), 'code')
            ->where('event_id', $event?->id);

        if ($promoCode instanceof EventPromoCode) {
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
            'ticket_type_ids' => ['required', 'array', 'min:1'],
            'ticket_type_ids.*' => [
                'integer',
                'distinct',
                Rule::exists((new EventTicketType)->getTable(), 'id')
                    ->where('account_id', $account?->id)
                    ->where('event_id', $event?->id),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $event = $this->route('event');

            if (! $event instanceof Event || $validator->errors()->has('ticket_type_ids')) {
                return;
            }

            $selectedCount = $event->ticketTypes()
                ->whereKey($this->input('ticket_type_ids', []))
                ->count();

            if ($selectedCount !== count(array_unique($this->input('ticket_type_ids', [])))) {
                $validator->errors()->add('ticket_type_ids', __('app.promo_code_invalid_ticket_types'));
            }
        }];
    }
}
