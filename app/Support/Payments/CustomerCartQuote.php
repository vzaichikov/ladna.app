<?php

namespace App\Support\Payments;

use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Support\MoneyFormatter;
use App\Support\Promotions\StudioPromoCodeService;
use App\Support\ScheduleKindRegistry;
use App\Support\TrialClassPassEligibility;
use Illuminate\Validation\ValidationException;

class CustomerCartQuote
{
    public function __construct(
        private readonly StudioPromoCodeService $promoCodes,
        private readonly TrialClassPassEligibility $trialEligibility,
    ) {}

    /**
     * @param  array<int, array{class_pass_plan_id:int, quantity:int}>  $lines
     * @return array<string, mixed>
     */
    public function execute(Account $account, Customer $customer, array $lines, ?string $code = null, bool $lockForUpdate = false): array
    {
        abort_unless($customer->account_id === $account->id, 404);
        $quantities = $this->quantities($lines);
        $query = $account->classPassPlans()->active()->whereIn('id', array_keys($quantities))->orderBy('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $catalog = $query->get();
        if ($catalog->count() !== count($quantities)) {
            $this->invalid('items', __('app.customer_cart_plan_unavailable'));
        }
        $plans = [];
        foreach ($catalog as $plan) {
            if (! $account->hasScheduleKindEnabled($plan->schedule_kind)
                || ! ScheduleKindRegistry::hasCapability($plan->schedule_kind, 'class_pass_eligible')
                || strtoupper($plan->currency) !== strtoupper($account->default_currency)) {
                $this->invalid('items', __('app.customer_cart_plan_unavailable'));
            }
            if ($plan->is_trial) {
                $this->assertTrialAvailable($account, $customer, $plan, array_sum($catalog->where('is_trial', true)->map(fn (ClassPassPlan $trial): int => $quantities[$trial->id])->all()));
            }
            for ($unit = 0; $unit < $quantities[$plan->id]; $unit++) {
                $plans[] = $plan;
            }
        }
        $promotion = filled($code) ? $this->promoCodes->quoteCart($account, $customer, $plans, (string) $code, $lockForUpdate) : null;
        $items = [];
        foreach ($plans as $position => $plan) {
            $discount = $promotion['quote']->lineDiscounts[$position] ?? 0;
            $items[] = [
                'class_pass_plan_id' => $plan->id, 'position' => $position,
                'plan_name' => $plan->name, 'plan_slug' => $plan->slug, 'schedule_kind' => $plan->schedule_kind->value,
                'subtotal_cents' => (int) $plan->price_cents, 'discount_cents' => $discount,
                'amount_cents' => max(0, (int) $plan->price_cents - $discount), 'currency' => $plan->currency,
                'sessions_count' => $plan->sessions_count, 'validity_days' => $plan->validity_days,
                'total_validity_days' => $plan->total_validity_days,
                'available_from_time' => $plan->available_from_time, 'available_until_time' => $plan->available_until_time,
                'allows_any_time' => $plan->allows_any_time, 'any_time_addon_price_cents' => $plan->any_time_addon_price_cents,
                'is_trial' => $plan->is_trial,
            ];
        }
        $subtotal = (int) array_sum(array_column($items, 'subtotal_cents'));
        if ($subtotal > 4294967295) {
            $this->invalid('items', __('app.customer_cart_quantity_invalid'));
        }
        $discount = (int) array_sum(array_column($items, 'discount_cents'));
        $promo = $promotion ? $promotion['promoCode'] : null;
        $hash = hash('sha256', json_encode([
            'account' => $account->id, 'customer' => $customer->id, 'items' => $items,
            'promo' => $promo ? [$promo->id, $promo->updated_at?->toISOString(), $promo->discount_type->value, $promo->discount_value, $promo->buy_quantity, $promo->free_quantity] : null,
        ], JSON_THROW_ON_ERROR));
        $summaries = collect($items)->groupBy('class_pass_plan_id')->map(function ($units): array {
            $first = $units->first();
            $currency = $first['currency'];

            return [
                'class_pass_plan_id' => $first['class_pass_plan_id'], 'plan_name' => $first['plan_name'], 'quantity' => $units->count(),
                'free_quantity' => $units->filter(fn (array $item): bool => $item['amount_cents'] === 0 && $item['subtotal_cents'] > 0)->count(),
                'subtotal' => MoneyFormatter::format($units->sum('subtotal_cents'), $currency),
                'discount' => MoneyFormatter::format($units->sum('discount_cents'), $currency),
                'total' => MoneyFormatter::format($units->sum('amount_cents'), $currency),
            ];
        })->values()->all();

        return [
            'items' => $items, 'promotion' => $promotion, 'quote_hash' => $hash, 'lines' => $summaries,
            'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'total_cents' => $subtotal - $discount,
            'currency' => $account->default_currency,
            'subtotal' => MoneyFormatter::format($subtotal, $account->default_currency),
            'discount' => MoneyFormatter::format($discount, $account->default_currency),
            'total' => MoneyFormatter::format($subtotal - $discount, $account->default_currency),
            'requires_payment' => $subtotal > $discount,
        ];
    }

    /** @param array<int, array{class_pass_plan_id:int, quantity:int}> $lines
     * @return array<int, int>
     */
    public function quantities(array $lines): array
    {
        $quantities = [];
        foreach ($lines as $line) {
            $id = (int) $line['class_pass_plan_id'];
            $quantity = (int) $line['quantity'];
            if ($id <= 0 || $quantity <= 0) {
                $this->invalid('items', __('app.customer_cart_quantity_invalid'));
            }
            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
        }
        if ($quantities === [] || array_sum($quantities) > 1000) {
            $this->invalid('items', __('app.customer_cart_quantity_invalid'));
        }
        ksort($quantities);

        return $quantities;
    }

    private function assertTrialAvailable(Account $account, Customer $customer, ClassPassPlan $plan, int $trialQuantity): void
    {
        if ($trialQuantity !== 1
            || CustomerClassPass::query()->whereBelongsTo($account)->whereBelongsTo($customer)
                ->where(fn ($query) => $query->whereHas('classPassPlan', fn ($plans) => $plans->where('is_trial', true))
                    ->orWhereHas('purchaseItem', fn ($items) => $items->where('is_trial', true)))->exists()
            || CustomerPurchase::query()->whereBelongsTo($account)->whereBelongsTo($customer)->reservingPromotionUse()
                ->where(fn ($query) => $query->whereHas('classPassPlan', fn ($plans) => $plans->where('is_trial', true))
                    ->orWhereHas('items', fn ($items) => $items->where('is_trial', true)))->exists()) {
            $this->invalid('items', __('app.trial_class_pass_not_available'));
        }
        $this->trialEligibility->assertAvailable($account, $customer, $plan, TrialClassPassEligibility::SourceOnlinePayment);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
