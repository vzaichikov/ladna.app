<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSubscriptionPriceVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', Rule::in(config('ladna.currencies'))],
            'trial_days' => ['required', 'integer', 'min:1', 'max:90'],
            'annual_discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'tiers' => ['required', 'array', 'min:1', 'max:50'],
            'tiers.*.starts_at_location' => ['required', 'integer', 'min:1', 'distinct'],
            'tiers.*.ends_at_location' => ['nullable', 'integer', 'min:1'],
            'tiers.*.unit_price_uah' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $tiers = $this->input('tiers', []);

            if (! is_array($tiers) || $tiers === []) {
                return;
            }

            if (collect($tiers)->contains(fn (mixed $tier): bool => ! is_array($tier))) {
                return;
            }

            usort($tiers, fn (array $left, array $right): int => ((int) ($left['starts_at_location'] ?? 0)) <=> ((int) ($right['starts_at_location'] ?? 0)));

            if ((int) ($tiers[0]['starts_at_location'] ?? 0) !== 1) {
                $validator->errors()->add('tiers', __('app.price_tiers_must_start_at_one'));
            }

            foreach ($tiers as $index => $tier) {
                $start = (int) ($tier['starts_at_location'] ?? 0);
                $end = filled($tier['ends_at_location'] ?? null) ? (int) $tier['ends_at_location'] : null;
                $isLast = $index === array_key_last($tiers);

                if ($end !== null && $end < $start) {
                    $validator->errors()->add('tiers', __('app.price_tier_end_before_start'));
                }

                if (! $isLast && $end === null) {
                    $validator->errors()->add('tiers', __('app.only_last_price_tier_open_ended'));
                }

                if ($isLast && $end !== null) {
                    $validator->errors()->add('tiers', __('app.last_price_tier_must_be_open_ended'));
                }

                if (! $isLast && (int) ($tiers[$index + 1]['starts_at_location'] ?? 0) !== ($end ?? 0) + 1) {
                    $validator->errors()->add('tiers', __('app.price_tiers_must_be_contiguous'));
                }
            }
        }];
    }
}
